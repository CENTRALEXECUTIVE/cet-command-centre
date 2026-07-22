<?php

namespace App\Services\Watchdog;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\JobNudge;
use App\Models\User;
use App\Models\WatchdogEvent;
use App\Services\Push\WebPushService;

/**
 * Pushes watchdog escalations to the office (Abdi & Maj), honouring each
 * admin's notification preferences (per-event-type toggles + a critical-only
 * master switch). Every send is logged to watchdog_events for the alerts
 * feed, and cadence-limited sends are recorded in job_nudges (recipient_type
 * 'admin') so nothing repeats faster than intended.
 */
class AdminAlerts
{
    public function __construct(private readonly WebPushService $push) {}

    /**
     * Push an alert to every admin whose preferences allow it, at most once
     * per $repeatMinutes per (booking, type), stopping after $maxSends when
     * one is given. Returns true when a send happened.
     */
    public function send(
        Booking $booking,
        string $nudgeType,
        string $prefType,
        string $title,
        string $body,
        string $severity = 'warning',
        ?int $maxSends = 1,
        int $repeatMinutes = 30,
    ): bool {
        $previous = JobNudge::where('booking_id', $booking->id)
            ->where('nudge_type', $nudgeType)
            ->where('recipient_type', 'admin')
            ->orderByDesc('sent_at')
            ->get();

        if ($maxSends !== null && $previous->count() >= $maxSends) {
            return false;
        }
        if ($previous->isNotEmpty() && $previous->first()->sent_at->gt(now()->subMinutes($repeatMinutes))) {
            return false;
        }

        $this->broadcast($prefType, $title, $body, $severity, $booking);

        JobNudge::create([
            'booking_id' => $booking->id,
            'nudge_type' => $nudgeType,
            'recipient_type' => 'admin',
            'sent_at' => now(),
            'channel' => 'push',
            'created_at' => now(),
        ]);

        WatchdogEvent::log($nudgeType, $title, $severity, $booking);

        return true;
    }

    /**
     * One-shot alert straight from an event (no cadence bookkeeping) — used by
     * flows that already fire exactly once, e.g. a cancel tap or a calendar
     * import. The caller logs (or has logged) its own feed row.
     */
    public function notify(string $prefType, string $title, string $body, string $severity = 'info', ?Booking $booking = null): void
    {
        $this->broadcast($prefType, $title, $body, $severity, $booking);
    }

    private function broadcast(string $prefType, string $title, string $body, string $severity, ?Booking $booking): void
    {
        $url = $booking ? route('bookings.show', $booking) : route('dashboard');

        User::where('role', UserRole::Admin->value)->where('is_active', true)->get()
            ->filter(fn (User $admin) => $admin->wantsAlert($prefType, $severity))
            ->each(fn (User $admin) => $this->push->sendToUser($admin, $title, $body, [
                'url' => $url,
                'tag' => 'alert-'.$prefType.'-'.($booking?->id ?? 'general'),
            ]));
    }
}
