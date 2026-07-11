<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\User;

/**
 * GPS location handling (GDPR sensitive).
 *
 * A driver's location is ONLY recorded while they are on a TRACKED job —
 * from the Set off tap (en route) through arrived/POB until Complete. Before
 * set-off, and when there is no job, the ping is rejected — location tracking
 * is off. Pings are retained for at most config('cet.gps_retention_days')
 * days and pruned automatically.
 */
class DriverLocationService
{
    /**
     * Record a GPS ping for a driver, attributing it to their current tracked
     * job. Returns null (and stores nothing) when the driver has not set off.
     */
    public function recordPing(User $driver, float $lat, float $lng, array $extra = []): ?DriverLocation
    {
        $booking = $this->trackedBookingFor($driver);

        if (! $booking) {
            return null; // Location OFF before set-off / when not on a job.
        }

        return DriverLocation::create([
            'driver_id' => $driver->id,
            'booking_id' => $booking->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'heading' => $extra['heading'] ?? null,
            'speed' => $extra['speed'] ?? null,
            'accuracy' => $extra['accuracy'] ?? null,
            'captured_at' => now(),
        ]);
    }

    public function activeBookingFor(User $driver): ?Booking
    {
        return Booking::forDriver($driver->id)
            ->active()
            ->orderBy('pickup_at')
            ->first();
    }

    /** The job GPS pings attach to — only after the driver has set off. */
    public function trackedBookingFor(User $driver): ?Booking
    {
        return Booking::forDriver($driver->id)
            ->tracked()
            ->orderBy('pickup_at')
            ->first();
    }

    public function latestForBooking(Booking $booking): ?DriverLocation
    {
        return DriverLocation::where('booking_id', $booking->id)->latest('captured_at')->first();
    }

    /** Delete GPS pings older than the retention window. Returns rows removed. */
    public function prune(): int
    {
        $cutoff = now()->subDays((int) config('cet.gps_retention_days', 90));

        return DriverLocation::where('captured_at', '<', $cutoff)->delete();
    }
}
