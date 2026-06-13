<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\DriverLocationService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpsTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        $this->driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $this->driver->id, 'is_third_party' => true]);
    }

    private function job(BookingStatus $status): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['driver_id' => $this->driver->id, 'status' => $status, 'pickup_at' => now()->addHour()]);
    }

    public function test_ping_is_stored_while_on_an_active_job(): void
    {
        $job = $this->job(BookingStatus::EnRoute);

        $this->actingAs($this->driver)
            ->postJson(route('driver.locations.store'), ['lat' => 53.3811, 'lng' => -1.4701])
            ->assertOk()
            ->assertJson(['tracking' => true, 'booking_id' => $job->id]);

        $this->assertDatabaseHas('driver_locations', [
            'driver_id' => $this->driver->id, 'booking_id' => $job->id,
        ]);
    }

    public function test_ping_is_rejected_when_not_on_a_job(): void
    {
        // Only a completed job → not active → location OFF.
        $this->job(BookingStatus::Complete);

        $this->actingAs($this->driver)
            ->postJson(route('driver.locations.store'), ['lat' => 53.3811, 'lng' => -1.4701])
            ->assertStatus(202)
            ->assertJson(['tracking' => false]);

        $this->assertEquals(0, DriverLocation::count());
    }

    public function test_old_gps_pings_are_pruned(): void
    {
        $job = $this->job(BookingStatus::EnRoute);
        DriverLocation::create([
            'driver_id' => $this->driver->id, 'booking_id' => $job->id,
            'latitude' => 1, 'longitude' => 1, 'captured_at' => now()->subDays(120),
        ]);
        DriverLocation::create([
            'driver_id' => $this->driver->id, 'booking_id' => $job->id,
            'latitude' => 2, 'longitude' => 2, 'captured_at' => now()->subDays(10),
        ]);

        $removed = app(DriverLocationService::class)->prune();

        $this->assertEquals(1, $removed);
        $this->assertEquals(1, DriverLocation::count());
    }

    public function test_public_tracking_location_feed_returns_latest_position(): void
    {
        $job = $this->job(BookingStatus::EnRoute);
        $link = $job->trackingLink()->create(['token' => 'tok123', 'expires_at' => now()->addHours(3)]);
        DriverLocation::create([
            'driver_id' => $this->driver->id, 'booking_id' => $job->id,
            'latitude' => 53.5, 'longitude' => -1.5, 'captured_at' => now(),
        ]);

        $this->getJson(route('track.location', $link->token))
            ->assertOk()
            ->assertJson(['available' => true, 'status' => 'en_route']);
    }
}
