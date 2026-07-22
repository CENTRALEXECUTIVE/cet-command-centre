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

    /** Average door-to-door speed assumption for drive-time estimates (mph). */
    private const AVG_MPH = 28.0;

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

        return max(1, (int) round(($miles / self::AVG_MPH) * 60));
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
