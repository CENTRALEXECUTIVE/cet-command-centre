<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Messaging\NotificationTemplates;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ETO-style "Send a notification": the office picks a template, a recipient and a
 * channel, and gets a ready-to-send message (WhatsApp / SMS / email deep link) to
 * send by hand — plus a Message record for the history. Nothing auto-sends to a
 * customer (the "manual messaging" rule); the office taps to send.
 */
class BookingNotificationController extends Controller
{
    public function __construct(private readonly NotificationTemplates $templates) {}

    public function send(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(NotificationTemplates::TEMPLATES))],
            'recipient' => ['required', Rule::in(array_keys(NotificationTemplates::RECIPIENTS))],
            'channel' => ['required', Rule::in(['whatsapp', 'sms', 'email'])],
        ]);

        [$phone, $email, $who] = $this->resolveRecipient($booking, $data['recipient']);
        $body = $this->templates->body($booking, $data['template']);
        $label = NotificationTemplates::label($data['template']);

        // Record it for the history (sent by hand, so queued until marked sent).
        $booking->messages()->create([
            'customer_id' => $data['recipient'] === 'customer' ? $booking->customer_id : null,
            'channel' => $data['channel'],
            'direction' => 'out',
            'type' => 'notify_'.$data['template'],
            'to_address' => $data['channel'] === 'email' ? $email : $phone,
            'body' => $body,
            'status' => 'queued',
        ]);

        // Build the deep link for the chosen channel so the office can send now.
        $link = $this->deepLink($data['channel'], $phone, $email, $booking, $label, $body);
        if (! $link) {
            return back()
                ->with('error', 'No '.($data['channel'] === 'email' ? 'email' : 'phone number').' on file for the '.$who.'.')
                ->with('scroll', 'send-notification');
        }

        return back()
            ->with('status', $label.' notification ready for the '.$who.' — tap to send.')
            ->with('notify_link', $link)
            ->with('notify_channel', $data['channel'])
            ->with('notify_copy', $body)
            ->with('scroll', 'send-notification');
    }

    /** @return array{0: ?string, 1: ?string, 2: string} [phone, email, who] */
    private function resolveRecipient(Booking $booking, string $recipient): array
    {
        if ($recipient === 'driver') {
            $md = $booking->meta['driver_details'] ?? [];
            $phone = $booking->driver?->phone ?: ($md['phone'] ?? null);
            $email = $booking->driver?->email;

            return [$phone, $email, 'driver'];
        }

        $phone = $booking->customerContactNumber() ?: $booking->customer?->phone;
        $email = $booking->customer?->email;

        return [$phone, $email, 'customer'];
    }

    private function deepLink(string $channel, ?string $phone, ?string $email, Booking $booking, string $label, string $body): ?string
    {
        if ($channel === 'email') {
            if (blank($email)) {
                return null;
            }
            $ref = $booking->external_reference ?: $booking->reference;
            $plain = preg_replace('/\*(.+?)\*/s', '$1', $body);

            return 'mailto:'.$email
                .'?subject='.rawurlencode('Central Executive Transfers — '.$label.' ('.$ref.')')
                .'&body='.rawurlencode($plain);
        }

        if ($channel === 'whatsapp') {
            $wa = Phone::wa($phone);

            return $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode($body) : null;
        }

        // SMS deep link (opens the operator's messaging app).
        if (blank($phone)) {
            return null;
        }
        $plain = preg_replace('/\*(.+?)\*/s', '$1', $body);

        return 'sms:'.preg_replace('/[^0-9+]/', '', $phone).'?&body='.rawurlencode($plain);
    }
}
