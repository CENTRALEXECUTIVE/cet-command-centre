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
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(40)
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
}
