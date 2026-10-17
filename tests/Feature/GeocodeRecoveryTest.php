<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use App\Services\GeocodingService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A failed geocode (e.g. the Google Maps key briefly missing) must NOT disable a
 * booking's location checks forever — it retries and recovers.
 */
class GeocodeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    /** Swap the geocoder for one that returns a fixed point (or null). */
    private function fakeGeocoder(?array $point): void
    {
        $this->app->instance(GeocodingService::class, new class($point) extends GeocodingService
        {
            public function __construct(private ?array $point) {}

            public function coords(?string $address): ?array
            {
                return $this->point;
            }
        });
    }

    private function booking(): Booking
    {
        return Booking::factory()->create([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->firstOrFail()->id,
            'pickup_address' => '9 Harney Close, Sheffield S9 5BW',
            'destination_address' => 'Manchester Airport',
        ]);
    }

    public function test_a_failed_geocode_is_not_cached_as_a_permanent_null(): void
    {
        $this->fakeGeocoder(null);           // key down / lookup fails
        $b = $this->booking();

        $b->ensureEndsGeocoded();
        $b = $b->fresh();

        // No poisoned null coord; a retry timestamp instead, so it will try again.
        $this->assertNull($b->pickupCoords());
        $this->assertArrayNotHasKey('pickup', $b->meta['geo'] ?? []);
        $this->assertArrayHasKey('pickup_tried_at', $b->meta['geo'] ?? []);
        $this->assertFalse($b->endsGeocoded());
    }

    public function test_it_recovers_once_the_key_works(): void
    {
        $this->fakeGeocoder(null);
        $b = $this->booking();
        $b->ensureEndsGeocoded(); // fails, records tried_at

        // Key now works, and the cooldown has passed.
        $this->fakeGeocoder([53.38, -1.42]);
        $b = $b->fresh();
        $meta = $b->meta;
        $meta['geo']['pickup_tried_at'] = now()->subMinutes(15)->toIso8601String();
        $meta['geo']['dropoff_tried_at'] = now()->subMinutes(15)->toIso8601String();
        $b->forceFill(['meta' => $meta])->save();

        $b->ensureEndsGeocoded();
        $b = $b->fresh();

        $this->assertSame([53.38, -1.42], $b->pickupCoords());
        $this->assertTrue($b->endsGeocoded());
    }

    public function test_within_the_cooldown_it_does_not_hammer_the_geocoder(): void
    {
        $this->fakeGeocoder(null);
        $b = $this->booking();
        $b->ensureEndsGeocoded();

        // A geocoder that would throw if called again proves we respect the cooldown.
        $this->app->instance(GeocodingService::class, new class extends GeocodingService
        {
            public function coords(?string $address): ?array
            {
                throw new \RuntimeException('should not be called within cooldown');
            }
        });

        $b->fresh()->ensureEndsGeocoded(); // must be a quiet no-op
        $this->assertTrue(true);
    }
}
