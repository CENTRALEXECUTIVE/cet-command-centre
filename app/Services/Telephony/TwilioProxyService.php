<?php

namespace App\Services\Telephony;

use App\Models\Booking;
use App\Models\ProxyEvent;
use App\Models\ProxySession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio Proxy number masking. One session per dispatched booking links two
 * participants — the customer and the assigned driver — through temporary
 * masked numbers from a small UK pool. Neither party ever sees the other's
 * real number; the mask dies 4 hours after the expected drop-off (and
 * immediately on complete/cancel/reassign).
 *
 * Uses Twilio's REST API directly via the HTTP client (same pattern as
 * WhatsAppService) — deliberately NO SDK dependency, so a deploy needs no
 * composer step. A silent no-op until TWILIO_PROXY_SERVICE_SID (plus the
 * existing SID/token) is set in .env, so nothing breaks before go-live.
 *
 * IMPORTANT: opening/closing a mask must never block dispatch — every Twilio
 * failure is caught, logged and audited, and the booking flow continues.
 */
class TwilioProxyService
{
    private const BASE = 'https://proxy.twilio.com/v1';

    /** Mask lifetime beyond the expected drop-off. */
    private const GRACE_HOURS = 4;

    public function configured(): bool
    {
        return filled(config('services.twilio.sid'))
            && filled(config('services.twilio.token'))
            && filled(config('services.twilio.proxy_service_sid'));
    }

    /**
     * Open (or refresh) the masked session for a booking's assigned driver.
     * Reuses an existing open session for the same driver; a session held by
     * a PREVIOUS driver is closed first so they lose contact immediately.
     */
    public function openSession(Booking $booking, User $driver): ?ProxySession
    {
        $customerPhone = $booking->customer?->phone;
        if (! $this->configured() || blank($customerPhone) || blank($driver->phone)) {
            return null;
        }

        $existing = $booking->proxySessions()->open()->latest('opened_at')->first();
        if ($existing) {
            if ($existing->driver_id === $driver->id) {
                return $existing; // already masked for this driver
            }
            $this->closeSession($booking, 'driver reassigned');
        }

        $closesAt = $this->closesAtFor($booking);

        try {
            $session = $this->twilio()->post($this->url('/Sessions'), [
                'UniqueName' => 'CET-'.$booking->reference.'-'.now()->format('YmdHis'),
                'DateExpiry' => $closesAt->copy()->utc()->toIso8601String(),
                'Mode' => 'voice-and-message',
            ])->throw()->json();

            $customer = $this->twilio()->post($this->url("/Sessions/{$session['sid']}/Participants"), [
                'Identifier' => $this->normalise($customerPhone),
                'FriendlyName' => 'Customer',
            ])->throw()->json();

            $driverPart = $this->twilio()->post($this->url("/Sessions/{$session['sid']}/Participants"), [
                'Identifier' => $this->normalise($driver->phone),
                'FriendlyName' => 'Driver',
            ])->throw()->json();
        } catch (\Throwable $e) {
            Log::warning('Twilio Proxy open failed', ['booking' => $booking->reference, 'error' => $e->getMessage()]);
            $this->audit(null, $booking, 'open_failed', ['error' => $e->getMessage()]);

            return null;
        }

        $row = $booking->proxySessions()->create([
            'twilio_session_sid' => $session['sid'],
            'customer_participant_sid' => $customer['sid'] ?? null,
            'driver_participant_sid' => $driverPart['sid'] ?? null,
            'driver_id' => $driver->id,
            // proxy_identifier = the number THAT participant dials/sees.
            'masked_number' => $driverPart['proxy_identifier'] ?? null,
            'customer_masked_number' => $customer['proxy_identifier'] ?? null,
            'status' => 'open',
            'opened_at' => now(),
            'closes_at' => $closesAt,
        ]);

        $this->audit($row, $booking, 'session_opened', [
            'driver' => $driver->name,
            'closes_at' => $closesAt->toDateTimeString(),
        ]);

        return $row;
    }

    /** Close the booking's open mask (job done/cancelled/reassigned/expired). */
    public function closeSession(Booking $booking, string $reason = 'closed'): void
    {
        $booking->proxySessions()->open()->get()
            ->each(fn (ProxySession $s) => $this->close($s, $reason));
    }

    /** Swap the mask to a new driver — the old driver loses contact now. */
    public function reassignDriver(Booking $booking, User $newDriver): ?ProxySession
    {
        $this->closeSession($booking, 'driver reassigned');

        return $this->openSession($booking, $newDriver);
    }

    /** Safety net: close every session past its closes_at. Returns count. */
    public function closeExpired(): int
    {
        $due = ProxySession::open()->where('closes_at', '<=', now())->with('booking')->get();

        foreach ($due as $session) {
            $this->close($session, 'expired');
        }

        return $due->count();
    }

    private function close(ProxySession $session, string $reason): void
    {
        if ($this->configured() && $session->twilio_session_sid) {
            try {
                $this->twilio()->post($this->url("/Sessions/{$session->twilio_session_sid}"), [
                    'Status' => 'closed',
                ])->throw();
            } catch (\Throwable $e) {
                // Log but still mark closed locally — the DateExpiry on the
                // Twilio side guarantees the mask dies regardless.
                Log::warning('Twilio Proxy close failed', ['sid' => $session->twilio_session_sid, 'error' => $e->getMessage()]);
            }
        }

        $session->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->audit($session, $session->booking, 'session_closed', ['reason' => $reason]);
    }

    /** Record a Twilio callback (call/message/out-of-session) for the audit trail. */
    public function recordWebhookEvent(array $payload): ProxyEvent
    {
        $sid = $payload['interactionSessionSid'] ?? $payload['sessionSid'] ?? $payload['SessionSid'] ?? null;
        $session = $sid ? ProxySession::where('twilio_session_sid', $sid)->first() : null;

        return ProxyEvent::create([
            'proxy_session_id' => $session?->id,
            'booking_id' => $session?->booking_id,
            'event_type' => $payload['interactionType'] ?? $payload['CallStatus'] ?? 'callback',
            'payload' => $this->scrub($payload),
            'occurred_at' => now(),
        ]);
    }

    /** The mask outlives the job by 4h past the expected drop-off. */
    public function closesAtFor(Booking $booking): \Illuminate\Support\Carbon
    {
        $duration = (int) ($booking->meta['duration_minutes'] ?? 60);

        return $booking->pickup_at->copy()->addMinutes($duration)->addHours(self::GRACE_HOURS);
    }

    private function twilio(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()
            ->withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.token'))
            ->timeout(12);
    }

    private function url(string $path): string
    {
        return self::BASE.'/Services/'.config('services.twilio.proxy_service_sid').$path;
    }

    private function normalise(string $number): string
    {
        $n = preg_replace('/[^\d+]/', '', $number);

        return str_starts_with($n, '0') ? '+44'.substr($n, 1) : $n;
    }

    private function audit(?ProxySession $session, ?Booking $booking, string $type, array $payload = []): void
    {
        try {
            ProxyEvent::create([
                'proxy_session_id' => $session?->id,
                'booking_id' => $booking?->id,
                'event_type' => $type,
                'payload' => $payload,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit must never break the booking flow (e.g. pre-migration).
        }
    }

    /** Keep message bodies out of the audit log — metadata only (GDPR). */
    private function scrub(array $payload): array
    {
        unset($payload['Body'], $payload['body'], $payload['interactionData']);

        return $payload;
    }
}
