<?php

namespace App\Http\Controllers;

use App\Models\TrackingLink;
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
}
