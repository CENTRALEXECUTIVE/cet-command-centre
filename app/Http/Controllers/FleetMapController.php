<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Live fleet map for the office: every driver currently on a job, plotted from
 * their latest GPS ping, with the job they're on. Uses the existing Google Maps
 * key (free) and falls back to a plain list when no key is set.
 */
class FleetMapController extends Controller
{
    private const ACTIVE = [
        BookingStatus::Accepted->value,
        BookingStatus::EnRoute->value,
        BookingStatus::Arrived->value,
        BookingStatus::Collected->value,
    ];

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('fleet.map', [
            'mapsKey' => Setting::mapsKey(),
            'drivers' => $this->positions(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['drivers' => $this->positions()]);
    }

    /**
     * One entry per driver currently on a job: their latest ping + the job.
     *
     * @return array<int, array<string, mixed>>
     */
    private function positions(): array
    {
        $bookings = Booking::whereIn('status', self::ACTIVE)
            ->whereNotNull('driver_id')
            ->with(['driver.driverProfile', 'customer'])
            ->orderBy('pickup_at')
            ->get();

        $out = [];
        $seen = [];
        foreach ($bookings as $b) {
            if (in_array($b->driver_id, $seen, true)) {
                continue; // one marker per driver
            }

            $loc = DriverLocation::where('driver_id', $b->driver_id)
                ->orderByDesc('captured_at')
                ->first();
            if (! $loc) {
                continue; // no GPS yet
            }

            $seen[] = $b->driver_id;
            $out[] = [
                'driver' => $b->driver->driverProfile?->callsign ?: $b->driver->name,
                'lat' => (float) $loc->latitude,
                'lng' => (float) $loc->longitude,
                'status' => $b->status->label(),
                'customer' => $b->customer?->name,
                'destination' => $b->destination_address,
                'ref' => $b->reference,
                'url' => route('bookings.show', $b),
                'ago' => $loc->captured_at?->diffForHumans(),
            ];
        }

        return $out;
    }
}
