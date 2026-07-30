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
 *
 * The permanent numbers can be PRINTED on the job sheet 24h+ ahead, but they only
 * CONNECT from OPEN_BEFORE_MINUTES before pickup — earlier calls/texts get the
 * polite "contact the office" reply, so nobody reaches anyone before the job is
 * genuinely imminent.
 */
class MaskingService
{
    /**
     * How long before pickup the line starts connecting. The number is on the
     * job sheet earlier, but a call/text won't bridge until this window opens —
     * 90 min covers a driver setting off early for a distant airport run.
     */
    private const OPEN_BEFORE_MINUTES = 90;

    /**
     * Grace after pickup so a job running long (not yet marked Complete) still
     * bridges. Once it IS marked Complete the line closes immediately regardless
     * of this window — see resolve().
     */
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
        // Terminal jobs never bridge: once a job is Completed (or Cancelled /
        // No-Show) the line goes dead both ways, so a driver can't reach the
        // customer after drop-off. Post-trip contact goes via the office.
        $terminal = [
            BookingStatus::Complete->value,
            BookingStatus::Cancelled->value,
            BookingStatus::NoShow->value,
        ];
        // Wide fetch, then filter each job by its OWN live window (pickup minus
        // the per-booking lead) and close time (drop-off plus the per-booking
        // grace) — so the office can shorten/lengthen the window per booking.
        //
        // When a caller is a party on MORE THAN ONE live job at once (e.g. two
        // pickups booked for the same minute), caller ID alone can't say which
        // they mean — so route to the one that's most ACTIVE (on board > arrived
        // > en route > accepted > allocated), then the soonest pickup. That gets
        // a driver working two same-time jobs onto the one that's actually
        // happening rather than an arbitrary DB order.
        $bookings = Booking::with(['customer', 'driver'])
            ->whereNotIn('status', $terminal)
            ->whereBetween('pickup_at', [now()->subHours(12), now()->addHours(12)])
            ->get()
            ->sortBy(fn (Booking $b) => [-$this->activeRank($b->status), $b->pickup_at->timestamp])
            ->values();

        // Remember a caller who IS on a job but is outside its live window, so we
        // can play a tailored "not yet / all done" message instead of the generic
        // one — but only if no OTHER job of theirs is currently connectable.
        $outOfWindow = null;

        foreach ($bookings as $booking) {
            $customer = $this->normalise($booking->customer?->phone);
            // The driver's number comes from an assigned system driver, else the
            // manually-entered driver details — so masking works either way.
            $driver = $this->normalise($booking->driver?->phone ?: ($booking->meta['driver_details']['phone'] ?? null));

            $isCustomer = $customer && $customer === $from && $driver;
            $isDriver = $driver && $driver === $from && $customer;
            if (! $isCustomer && ! $isDriver) {
                continue; // caller isn't a party on this job
            }

            // Inside this job's live window? (pickup − lead … drop-off + grace)
            $duration = (int) ($booking->meta['duration_minutes'] ?? 60);
            $closeAt = $booking->pickup_at->copy()->addMinutes($duration)
                ->addMinutes((int) round($booking->maskingGraceHours() * 60));

            if (! $booking->maskingWindowOpen()) {
                $outOfWindow ??= ['office' => true, 'reason' => 'too_early', 'booking' => $booking];

                continue;
            }
            if (now()->gt($closeAt)) {
                $outOfWindow ??= ['office' => true, 'reason' => 'closed', 'booking' => $booking];

                continue;
            }

            // Live → connect. Customer → driver, driver → customer.
            if ($isCustomer) {
                return ['dial' => $driver, 'caller_id' => $this->driverLine(), 'to' => 'driver', 'booking' => $booking];
            }

            return ['dial' => $customer, 'caller_id' => $this->customerLine(), 'to' => 'customer', 'booking' => $booking];
        }

        if ($outOfWindow) {
            return $outOfWindow;
        }

        return null;
    }

    /**
     * How "live" a status is, for choosing between concurrent jobs a caller is on
     * — the higher, the more the driver is actively working it right now.
     */
    private function activeRank(BookingStatus $status): int
    {
        return match ($status) {
            BookingStatus::Collected => 5,
            BookingStatus::Arrived => 4,
            BookingStatus::EnRoute => 3,
            BookingStatus::Accepted => 2,
            BookingStatus::Allocated => 1,
            default => 0,
        };
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

        $say = htmlspecialchars($this->officeMessage(is_array($resolved) ? ($resolved['reason'] ?? null) : null), ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            ."<Response><Say>{$say}</Say></Response>";
    }

    /**
     * The spoken/texted CET message when a call/text can't connect — tailored to
     * WHY: too early (before the line goes live), all done (after the job), or the
     * generic fallback for an unknown caller.
     */
    public function officeMessage(?string $reason = null): string
    {
        return match ($reason) {
            'too_early' => 'Thanks for calling Central Executive Transfers. Your driver isn\'t reachable on this line just yet — it opens closer to your pickup time. For anything urgent please call the office.',
            'closed' => 'Thanks for calling Central Executive Transfers. This journey\'s line has now closed. For anything further please call the office.',
            default => 'Sorry, we could not connect your call. Please contact Central Executive Transfers directly.',
        };
    }

    /**
     * Forward an inbound SMS to the job's counterpart, from the counterpart's CET
     * line so a reply routes straight back. Returns false if the caller isn't on
     * a current job (the webhook then replies with a polite "contact the office").
     */
    public function forwardSms(string $fromNumber, string $body): bool
    {
        $resolved = $this->resolve($fromNumber);
        // Not on a job, or on a job that's outside its live window → don't bridge.
        if (! $resolved || empty($resolved['dial'])) {
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

    /**
     * Normalise any UK number to E.164 (+44…) so a stored number and the caller
     * ID Twilio presents compare equal AND the number is dialable. Handles the
     * real-world spread we see in imported/typed data: 07…, +44…, 44… (no plus),
     * 0044…, a stray trunk 0 after the country code (+44 (0)7…), and a bare
     * 10-digit mobile with the leading 0 dropped. A number we can't confidently
     * place is returned digits-only with a leading + rather than silently broken.
     */
    private function normalise(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        $n = preg_replace('/[^0-9+]/', '', $number);
        // Strip any '+' (even mid-string from bad formatting); rebuild cleanly.
        $hadPlus = Str::startsWith($n, '+');
        $digits = str_replace('+', '', $n);
        if ($digits === '') {
            return null;
        }

        // 00 international prefix → country code.
        if (Str::startsWith($digits, '00')) {
            $digits = substr($digits, 2);
            $hadPlus = true;
        }

        // Already carries the UK country code (with or without the +).
        if ($hadPlus || Str::startsWith($digits, '44')) {
            if (Str::startsWith($digits, '44')) {
                // Drop a trunk 0 written after the country code: 44(0)7… → 447…
                if (Str::startsWith($digits, '440')) {
                    $digits = '44'.substr($digits, 3);
                }

                return '+'.$digits;
            }

            return '+'.$digits; // some other +CC number (kept as given)
        }

        // UK local: 07… → +447…
        if (Str::startsWith($digits, '0')) {
            return '+44'.substr($digits, 1);
        }

        // Bare 10-digit mobile with the leading 0 dropped (7…) → +447…
        if (strlen($digits) === 10 && Str::startsWith($digits, '7')) {
            return '+44'.$digits;
        }

        return '+'.$digits;
    }
}
