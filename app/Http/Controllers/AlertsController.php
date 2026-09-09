<?php

namespace App\Http\Controllers;

use App\Models\WatchdogEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The control-tower alerts feed (admin dashboard): recent watchdog events,
 * newest first, plus the unacknowledged-critical count for the nav badge.
 * Critical rows glow until acknowledged.
 */
class AlertsController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        // Crash-safe before the migration has run: an empty feed beats a 500
        // that leaves the dashboard panel stuck on "Loading…".
        try {
            return $this->buildFeed();
        } catch (\Throwable) {
            return response()->json(['events' => [], 'critical' => 0]);
        }
    }

    private function buildFeed(): JsonResponse
    {
        $events = WatchdogEvent::with('booking')
            // Only what still needs attention: once an alert is acknowledged
            // (dealt with) it drops off the feed. Routine per-status-change log
            // rows are excluded — they're noise, not alerts. Anything older than
            // a day is stale — it drops off on its own so the feed stays live and
            // doesn't hoard yesterday's alerts.
            ->whereNull('acknowledged_at')
            ->where('event_type', '!=', 'status_changed')
            ->where('occurred_at', '>=', now()->subDay())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (WatchdogEvent $e) => [
                'id' => $e->id,
                'time' => $e->occurred_at->format('H:i'),
                'severity' => $e->severity,
                'title' => $e->title,
                'acknowledged' => $e->acknowledged_at !== null,
                'url' => $e->booking ? route('bookings.show', $e->booking) : null,
            ]);

        return response()->json([
            'events' => $events,
            'critical' => WatchdogEvent::unacknowledgedCritical()->count(),
        ]);
    }

    public function acknowledge(Request $request, WatchdogEvent $event): JsonResponse
    {
        if ($event->acknowledged_at === null) {
            $event->forceFill(['acknowledged_at' => now()])->save();
        }

        return response()->json([
            'ok' => true,
            'critical' => WatchdogEvent::unacknowledgedCritical()->count(),
        ]);
    }

    /** Acknowledge every outstanding alert in one go — clears the whole feed. */
    public function acknowledgeAll(Request $request): JsonResponse
    {
        WatchdogEvent::whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);

        return response()->json(['ok' => true, 'critical' => 0]);
    }

    /**
     * Toggle "I'm on a job — hold my emergency alerts". While held, the at-risk
     * auto-call routes to the OTHER director instead of ringing this one next to a
     * passenger. Auto-expires so it can never be left on for ever.
     */
    public function toggleHold(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        if ($user->alertsHeld()) {
            $user->releaseAlerts();

            return back()->with('status', 'You’re back on alerts — emergencies will reach you again.');
        }

        $minutes = (int) config('cet.alerts_hold_minutes', 180);
        $user->holdAlertsFor($minutes);

        return back()->with('status', 'Alerts held for '.round($minutes / 60, 1).'h — emergencies go to the other director until '
            .$user->fresh()->alerts_busy_until->format('H:i').'.');
    }
}
