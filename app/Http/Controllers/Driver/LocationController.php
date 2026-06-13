<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives GPS pings from the driver app (every ~5 minutes while on an active
 * job). The service rejects pings when the driver is not on a job, so the app
 * stops tracking automatically once a job completes.
 */
class LocationController extends Controller
{
    public function __construct(private readonly DriverLocationService $locations) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
        ]);

        $location = $this->locations->recordPing(
            $request->user(),
            (float) $data['lat'],
            (float) $data['lng'],
            $data,
        );

        if (! $location) {
            // No active job → tell the app to stop pinging.
            return response()->json(['tracking' => false], 202);
        }

        return response()->json([
            'tracking' => true,
            'booking_id' => $location->booking_id,
            'next_ping_seconds' => (int) config('cet.gps_ping_seconds', 300),
        ]);
    }
}
