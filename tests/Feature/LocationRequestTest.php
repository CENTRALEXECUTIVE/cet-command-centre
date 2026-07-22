<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Office "request location": an admin can ask the assigned driver to share
 * where they are right now, and the driver answers with a one-off ping — at any
 * live stage, even before Set off.
 */
class LocationRequestTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => false]);

        return $driver;
    }

    private function allocatedBooking(User $driver): Booking
    {
        return Booking::factory()->create([
            'status' => BookingStatus::Allocated,
            'driver_id' => $driver->id,
            'pickup_at' => now()->addHour(),
        ]);
    }

    public function test_admin_can_request_a_drivers_location(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->allocatedBooking($driver);

        $this->actingAs($admin)->postJson(route('bookings.request-location', $booking))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNotNull($booking->fresh()->locationRequestedAt());
        $this->assertTrue($booking->fresh()->locationRequestPending());
    }

    public function test_request_is_rejected_when_there_is_no_live_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending, 'driver_id' => null]);

        $this->actingAs($admin)->postJson(route('bookings.request-location', $booking))
            ->assertStatus(422)
            ->assertJson(['reason' => 'no_live_driver']);
    }

    public function test_driver_can_share_location_before_set_off(): void
    {
        $driver = $this->driver();
        $booking = $this->allocatedBooking($driver);

        $this->actingAs($driver)->postJson(route('driver.job.location', $booking), [
            'lat' => 53.3811, 'lng' => -1.4701,
        ])->assertOk()->assertJson(['shared' => true]);

        $this->assertDatabaseHas('driver_locations', [
            'booking_id' => $booking->id,
            'driver_id' => $driver->id,
        ]);

        // The request is now answered — no longer pending.
        $booking->forceFill(['meta' => ['location_request_at' => now()->subMinute()->toIso8601String()]])->save();
        $this->assertFalse($booking->fresh()->locationRequestPending());
    }

    public function test_a_driver_cannot_share_for_someone_elses_job(): void
    {
        $mine = $this->driver();
        $other = $this->driver();
        $booking = $this->allocatedBooking($other);

        $this->actingAs($mine)->postJson(route('driver.job.location', $booking), [
            'lat' => 53.38, 'lng' => -1.47,
        ])->assertForbidden();

        $this->assertDatabaseCount('driver_locations', 0);
    }

    public function test_location_data_returns_the_latest_ping(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->allocatedBooking($driver);
        DriverLocation::create([
            'driver_id' => $driver->id, 'booking_id' => $booking->id,
            'latitude' => 53.3811, 'longitude' => -1.4701, 'captured_at' => now(),
        ]);

        $this->actingAs($admin)->getJson(route('bookings.location', $booking))
            ->assertOk()
            ->assertJsonPath('has_driver', true)
            ->assertJsonPath('ping.lat', 53.3811);
    }

    public function test_a_driver_cannot_poll_location_data(): void
    {
        $driver = $this->driver();
        $booking = $this->allocatedBooking($driver);

        $this->actingAs($driver)->getJson(route('bookings.location', $booking))->assertForbidden();
    }
}
