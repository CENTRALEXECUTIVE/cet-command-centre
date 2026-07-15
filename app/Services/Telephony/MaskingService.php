<?php

namespace App\Services\Telephony;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Number masking — the "switchboard" model. Two permanent CET lines bridge every
 * job with no per-job cost:
 *
 *   • customer_line — given to customers. A customer rings/texts it → we look up
 *     their upcoming/active job and connect them to their driver.
 *   • driver_line   — on the driver's job screen. A driver rings/texts it → we
 *     connect them to that job's customer.
 *
 * We identify the caller by their own phone number and route to the counterpart,
 * so the SAME two numbers serve unlimited concurrent jobs and can be handed out
 * 24h+ in advance (they never change). Neither party sees the other's real
 * number: the callee sees the CET line they'd call/text back on.
 */
class MaskingService
{
    /** How far ahead a masked call/text can connect (details go out ~24h before). */
    private const LOOKAHEAD_DAYS = 3;

    /** Grace after pickup so post-trip contact (a lost item) still bridges. */
    private const LOOKBEHIND_HOURS = 6;

    /** Both permanent lines set → the switchboard is live. */
    public function configured(): bool
    {
        return filled($this->customerLine()) && filled($this->driverLine());
    }

    public function customerLine(): ?string
    {
        return config('services.twilio_masking.customer_line');
    }

    public function driverLine(): ?string
    {
        return config('services.twilio_masking.driver_line');
    }

    /**
     * Resolve an inbound caller to the job's counterpart and the caller-ID the
     * callee should see (the line they'd use to reply). Returns null if the
     * caller isn't a known party on a current job.
     *
     * @return array{dial: string, caller_id: ?string, to: string, booking: Booking}|null
     */
    public function resolve(string $fromNumber): ?array
    {
        $from = $this->normalise($fromNumber);
        if (! $from) {
            return null;
        }

        // Any live/upcoming job in the window — whether the driver is an assigned
        // system user OR was typed into the manual "Driver for this job" form.
        $bookings = Booking::with(['customer', 'driver'])
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('pickup_at', [now()->subHours(self::LOOKBEHIND_HOURS), now()->addDays(self::LOOKAHEAD_DAYS)])
            ->orderBy('pickup_at') // nearest job wins if a caller has more than one
            ->get();

        foreach ($bookings as $booking) {
            $customer = $this->normalise($booking->customer?->phone);
            // The driver's number comes from an assigned system driver, else the
            // manually-entered driver details — so masking works either way.
            $driver = $this->normalise($booking->driver?->phone ?: ($booking->meta['driver_details']['phone'] ?? null));

            // Customer calling → reach the driver; the driver sees the DRIVER line.
            if ($customer && $customer === $from && $driver) {
                return ['dial' => $driver, 'caller_id' => $this->driverLine(), 'to' => 'driver', 'booking' => $booking];
            }
            // Driver calling → reach the customer; the customer sees the CUSTOMER line.
            if ($driver && $driver === $from && $customer) {
                return ['dial' => $customer, 'caller_id' => $this->customerLine(), 'to' => 'customer', 'booking' => $booking];
            }
        }

        return null;
    }

    /**
     * Back-compat: just the counterpart's number for an inbound caller (voice
     * bridge). Prefer resolve() when the caller-ID line is needed.
     */
    public function counterpartFor(string $fromNumber): ?string
    {
        return $this->resolve($fromNumber)['dial'] ?? null;
    }

    /** TwiML to bridge the call to the counterpart, or a polite failure. */
    public function dialTwiml(array|string|null $resolved): string
    {
        // Accept either resolve()'s array or a bare number (legacy callers).
        $dial = is_array($resolved) ? ($resolved['dial'] ?? null) : $resolved;
        $callerId = is_array($resolved)
            ? ($resolved['caller_id'] ?? $this->fallbackCallerId())
            : $this->fallbackCallerId();

        if ($dial) {
            $to = htmlspecialchars($dial, ENT_XML1);
            $cid = htmlspecialchars((string) $callerId, ENT_XML1);

            return '<?xml version="1.0" encoding="UTF-8"?>'
                ."<Response><Dial callerId=\"{$cid}\">{$to}</Dial></Response>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response><Say>Sorry, we could not connect your call. Please contact Central Executive Transfers directly.</Say></Response>';
    }

    /**
     * Forward an inbound SMS to the job's counterpart, from the counterpart's CET
     * line so a reply routes straight back. Returns false if the caller isn't on
     * a current job (the webhook then replies with a polite "contact the office").
     */
    public function forwardSms(string $fromNumber, string $body): bool
    {
        $resolved = $this->resolve($fromNumber);
        if (! $resolved) {
            return false;
        }

        return $this->sendSms($resolved['caller_id'], $resolved['dial'], $body);
    }

    private function sendSms(?string $from, string $to, string $body): bool
    {
        $sid = config('services.twilio.sid');
        if (blank($from) || blank($sid) || blank($body)) {
            return false;
        }

        try {
            Http::asForm()
                ->withBasicAuth((string) $sid, (string) config('services.twilio.token'))
                ->timeout(12)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To' => $this->normalise($to),
                    'Body' => Str::limit($body, 1500, ''),
                ])->throw();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Masking SMS forward failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function fallbackCallerId(): ?string
    {
        return $this->customerLine() ?? config('services.twilio_masking.proxy_number');
    }

    private function normalise(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }
        $number = preg_replace('/[^0-9+]/', '', $number);
        if (Str::startsWith($number, '0')) {
            $number = '+44'.substr($number, 1);
        }

        return $number;
    }
}
