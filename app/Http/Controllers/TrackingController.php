<?php

namespace App\Http\Controllers;

use App\Models\TrackingLink;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public live-tracking page reached via the tokenised link sent to the customer
 * when their driver goes En Route. No login required; the token is the secret.
 * The live map / GPS feed is layered on in Phase 3 — this confirms the driver
 * is on the way and shows journey status.
 */
class TrackingController extends Controller
{
    public function show(string $token): View|Response
    {
        $link = TrackingLink::with(['booking.driver', 'booking.vehicleType', 'booking.customer'])
            ->where('token', $token)
            ->first();

        if (! $link || ($link->expires_at && $link->expires_at->isPast())) {
            return response()->view('tracking.expired', [], 410);
        }

        $link->increment('view_count');
        $link->forceFill(['last_viewed_at' => now()])->save();

        return view('tracking.show', ['booking' => $link->booking]);
    }

    /**
     * JSON feed the tracking page polls for the driver's live position. The
     * position is only exposed while the job is actively running (en route →
     * on board) — once it completes or closes, only the final status message
     * remains, never a location.
     */
    public function location(string $token, DriverLocationService $locations): JsonResponse
    {
        $link = TrackingLink::with('booking')->where('token', $token)->first();

        if (! $link || ($link->expires_at && $link->expires_at->isPast())) {
            return response()->json(['available' => false], 410);
        }

        $booking = $link->booking;
        $live = $booking && in_array($booking->status->value, ['en_route', 'arrived', 'collected'], true);
        $position = $live ? $locations->latestForBooking($booking) : null;

        return response()->json([
            'available' => (bool) $position,
            'status' => $booking?->status->value,
            'status_label' => $booking?->status->label(),
            'message' => $booking?->trackingMessage(),
            'finished' => $booking?->status->isTerminal() ?? false,
            'latitude' => $position?->latitude,
            'longitude' => $position?->longitude,
            'updated_at' => $position?->captured_at?->toIso8601String(),
        ]);
    }
}
