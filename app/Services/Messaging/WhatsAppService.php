<?php

namespace App\Services\Messaging;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends WhatsApp messages to customers and records every one in the messages
 * table (audit + delivery tracking). Uses Twilio's WhatsApp API when credentials
 * are present; otherwise falls back to a "log" transport so confirmations and
 * reminders still flow in development and tests.
 */
class WhatsAppService
{
    /**
     * Queue/record and attempt delivery of a WhatsApp message.
     *
     * @param  array{type?:string, booking?:Booking, customer?:Customer, scheduled_for?:\DateTimeInterface}  $context
     */
    public function send(string $toNumber, string $body, array $context = []): Message
    {
        $booking = $context['booking'] ?? null;
        $customer = $context['customer'] ?? null;

        $message = Message::create([
            'booking_id' => $booking?->id,
            'customer_id' => $customer?->id ?? $booking?->customer_id,
            'channel' => 'whatsapp',
            'direction' => 'outbound',
            'type' => $context['type'] ?? 'custom',
            'to_address' => $toNumber,
            'body' => $body,
            'status' => 'queued',
            'scheduled_for' => $context['scheduled_for'] ?? null,
        ]);

        // Reminders and review requests are NEVER auto-delivered — the office
        // sends them by hand from their own WhatsApp. They stay queued on the
        // "to send" list until marked sent, even when due right now (e.g. a
        // last-minute booking whose reminder is due immediately). Delivering
        // them here would silently mark them sent and hide them from the list.
        if ($message->isScheduledPrompt()) {
            return $message;
        }

        // Future-dated messages are left queued for the scheduler to deliver.
        if ($message->scheduled_for && $message->scheduled_for->isFuture()) {
            return $message;
        }

        return $this->deliver($message);
    }

    /** Deliver a queued message now, updating its status. */
    public function deliver(Message $message): Message
    {
        if (blank($message->to_address)) {
            return $this->markFailed($message, 'No destination number.');
        }

        if (! $this->configured()) {
            // Log transport — record as sent so the workflow proceeds.
            Log::channel(config('logging.default'))->info('[WhatsApp:log] '.$message->to_address.' :: '.$message->body);

            return $this->markSent($message, providerId: 'log-'.$message->id);
        }

        try {
            $sid = config('services.twilio.sid');
            $token = config('services.twilio.token');

            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => 'whatsapp:'.config('services.twilio.whatsapp_from'),
                    'To' => 'whatsapp:'.$this->normalise($message->to_address),
                    'Body' => $message->body,
                ]);

            if ($response->successful()) {
                return $this->markSent($message, providerId: $response->json('sid'));
            }

            return $this->markFailed($message, $response->body());
        } catch (\Throwable $e) {
            return $this->markFailed($message, $e->getMessage());
        }
    }

    public function configured(): bool
    {
        return filled(config('services.twilio.sid'))
            && filled(config('services.twilio.token'))
            && filled(config('services.twilio.whatsapp_from'));
    }

    private function markSent(Message $message, string $providerId): Message
    {
        $message->update(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now()]);

        return $message;
    }

    private function markFailed(Message $message, string $reason): Message
    {
        $message->update(['status' => 'failed', 'meta' => ['error' => $reason]]);

        return $message;
    }

    /** Normalise a UK number to E.164 (+44…) for WhatsApp. */
    private function normalise(string $number): string
    {
        $number = preg_replace('/[^0-9+]/', '', $number);

        if (str_starts_with($number, '+')) {
            return $number;
        }
        if (str_starts_with($number, '0')) {
            return '+44'.substr($number, 1);
        }

        return '+'.$number;
    }
}
