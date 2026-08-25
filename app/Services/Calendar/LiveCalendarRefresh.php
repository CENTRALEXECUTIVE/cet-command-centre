<?php

namespace App\Services\Calendar;

use App\Models\Booking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Make the DRIVER-facing pages (the public link and the logged-in job screen)
 * show what's on the Google Calendar RIGHT NOW — the same live view the office
 * booking page already gets — instead of a snapshot saved at import time.
 *
 * It delegates to CalendarTimeSync::scan (the battle-tested, timezone-safe sync
 * the office page uses) so the driver sees exactly what the office sees: the
 * current pickup time, addresses, flight, luggage, child seats and payment,
 * all mirrored from the live event. The calendar is READ-ONLY — nothing is
 * ever written back to Google.
 *
 * A short per-booking cache stops a driver reloading the link (or the 8–10×
 * refresh test) from hitting the Google API on every request, while still being
 * live to within a few seconds. Everything is best-effort: if the calendar
 * isn't connected or a read fails, the stored copy is shown and the page never
 * breaks.
 */
class LiveCalendarRefresh
{
    /** Seconds a freshly-synced booking is trusted before we read Google again. */
    private const TTL = 30;

    public function __construct(private readonly CalendarTimeSync $timeSync) {}

    /** Refresh the booking from the live calendar in place. Safe to call always. */
    public function refresh(?Booking $booking): void
    {
        if (! $booking) {
            return;
        }

        // Freshness gate: sync at most once per booking per TTL, no matter how
        // many times the link is opened in that window.
        if (! Cache::add('cal_live_refresh_'.$booking->id, true, self::TTL)) {
            return;
        }

        try {
            $this->timeSync->scan($booking);
        } catch (\Throwable $e) {
            // Never let a calendar read break a driver's job screen.
            Log::warning('Live calendar refresh failed', [
                'booking' => $booking->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
