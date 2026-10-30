<?php

namespace App\Support;

/**
 * Small geo helpers for the status watchdog. Pure functions — no API calls —
 * so geofence checks cost nothing and are fully unit-testable.
 */
class Geo
{
    private const EARTH_RADIUS_M = 6371000.0;

    /** Road wiggle: straight-line → typical road distance multiplier. */
    private const ROAD_FACTOR = 1.3;

    /**
     * Drive-time speeds: the first miles of any trip are town driving, the rest is
     * mostly A-roads/motorway. One flat 28 mph made a Barnsley → Manchester Airport
     * run (≈47 road miles) read 100 min instead of ~75, and short hops too quick.
     */
    private const TOWN_MILES = 10.0;

    private const TOWN_MPH = 22.0;

    private const OPEN_ROAD_MPH = 50.0;

    /** Great-circle distance between two points, in metres. */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Rough drive time between two points in minutes (haversine × road factor
     * at an average speed). Deliberately API-free: the watchdog runs every
     * minute and must never rack up per-call Google costs. Good enough for
     * "when should the driver set off" with the clamped buffer on top.
     */
    public static function estimateDriveMinutes(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $miles = (self::haversineMeters($lat1, $lng1, $lat2, $lng2) / 1609.34) * self::ROAD_FACTOR;
        $town = min($miles, self::TOWN_MILES);
        $open = max(0.0, $miles - self::TOWN_MILES);

        return max(1, (int) round(($town / self::TOWN_MPH + $open / self::OPEN_ROAD_MPH) * 60));
    }

    /** Speed between two pings in mph, from displacement over elapsed time. */
    public static function speedMph(
        float $lat1, float $lng1, \DateTimeInterface $t1,
        float $lat2, float $lng2, \DateTimeInterface $t2,
    ): ?float {
        $seconds = abs($t2->getTimestamp() - $t1->getTimestamp());
        if ($seconds === 0) {
            return null;
        }

        $meters = self::haversineMeters($lat1, $lng1, $lat2, $lng2);

        return ($meters / $seconds) * 2.23694;
    }
}
