<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_haversine_known_distance_sheffield_to_manchester_airport(): void
    {
        // Sheffield city centre → Manchester Airport ≈ 53.4 km great-circle.
        $m = Geo::haversineMeters(53.3811, -1.4701, 53.3588, -2.2727);

        $this->assertEqualsWithDelta(53400, $m, 1500);
    }

    public function test_haversine_zero_for_identical_points(): void
    {
        $this->assertSame(0.0, Geo::haversineMeters(53.3811, -1.4701, 53.3811, -1.4701));
    }

    public function test_haversine_short_distance_accuracy(): void
    {
        // ~111 m per 0.001° of latitude.
        $m = Geo::haversineMeters(53.0, -1.5, 53.001, -1.5);

        $this->assertEqualsWithDelta(111.2, $m, 1.0);
    }

    public function test_drive_estimate_scales_with_distance_and_is_never_zero(): void
    {
        $near = Geo::estimateDriveMinutes(53.3811, -1.4701, 53.3830, -1.4720);
        $far = Geo::estimateDriveMinutes(53.3811, -1.4701, 53.3588, -2.2727);

        $this->assertGreaterThanOrEqual(1, $near);
        $this->assertGreaterThan($near, $far);
        // Sheffield→Manchester Airport ≈ 33 straight miles → ~43 road miles →
        // ~92 min at the conservative 28 mph average.
        $this->assertEqualsWithDelta(92, $far, 15);
    }

    public function test_speed_between_pings(): void
    {
        $t1 = new \DateTimeImmutable('2026-07-15 10:00:00');
        $t2 = new \DateTimeImmutable('2026-07-15 10:01:00');

        // 0.01° longitude at 53°N ≈ 666 m in 60 s ≈ 24.8 mph.
        $mph = Geo::speedMph(53.0, -1.50, $t1, 53.0, -1.51, $t2);

        $this->assertEqualsWithDelta(24.8, $mph, 1.5);
    }

    public function test_speed_is_null_for_zero_elapsed_time(): void
    {
        $t = new \DateTimeImmutable('2026-07-15 10:00:00');

        $this->assertNull(Geo::speedMph(53.0, -1.5, $t, 53.1, -1.5, $t));
    }
}
