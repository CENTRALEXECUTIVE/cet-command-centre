<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\WatchdogEvent;
use Illuminate\Support\Facades\Log;

/**
 * One place to handle a transactional email that failed to send. Email is
 * business-critical (confirmations, payment links), so a failure must NEVER be
 * silent: it's logged AND raised on the office alerts feed so someone acts on it
 * (the booking still has the WhatsApp/copy-link fallbacks). Never throws — the
 * caller has already caught the send error and must keep going.
 */
class MailFailure
{
    public static function report(string $what, \Throwable $e, ?Booking $booking = null, ?string $to = null): void
    {
        Log::warning('[mail] '.$what.' failed to send: '.$e->getMessage());

        // The booking's "📧 Emails sent" history shows the failure too.
        if ($booking && $booking->exists) {
            \App\Support\BookingEmailLog::recordFailure($booking, $what, $e, $to);
        }

        $ref = $booking ? ($booking->external_reference ?: $booking->reference) : null;
        $title = '✉️ Email didn’t send — '.$what.($ref ? ' ('.$ref.')' : '').'. Send it another way (copy link / WhatsApp) and check email settings.';

        // ONE alert per failed email, not one per retry (13 Nov 2026: a 5-minute retry
        // during the Microsoft outage stacked a new alert every run). While the same alert
        // is still un-ticked, or was ticked in the last 24h, a repeat failure stays quiet.
        try {
            $already = WatchdogEvent::query()
                ->where('event_type', 'email_failed')
                ->where('title', $title)
                ->where('booking_id', $booking?->id)
                ->where(fn ($q) => $q->whereNull('acknowledged_at')->orWhere('acknowledged_at', '>=', now()->subDay()))
                ->exists();
        } catch (\Throwable) {
            $already = false;
        }
        if (! $already) {
            WatchdogEvent::log('email_failed', $title, 'warning', $booking);
        }
    }
}
