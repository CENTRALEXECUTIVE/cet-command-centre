<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A driver can always finish the job — even if an intermediate tap (e.g.
 * "Passenger on board") was missed, and even if their location is off. The driver
 * is never blocked from recording a status; a missing/odd GPS fix is only flagged
 * for the office.
 */
class DriverCompleteFromDropoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function jobFor(User $driver, string $status): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $driver->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->firstOrFail()->id,
            'status' => $status,
            'pickup_at' => now()->addHour(),
            'pickup_address' => '9 Harney Close, Sheffield',
            'destination_address' => 'Manchester Airport',
        ]);
    }

    public function test_a_driver_completes_from_enroute_when_pob_was_missed(): void
    {
        $driver = User::factory()->driver()->create();
        // Job never reached "Passenger on board" — normally Complete is unreachable.
        $booking = $this->jobFor($driver, BookingStatus::EnRoute->value);

        $this->actingAs($driver)->post(route('driver.job.status', $booking), [
            'status' => 'complete', 'lat' => 53.35, 'lng' => -1.47, 'accuracy' => 20,
        ])->assertRedirect();

        $this->assertSame('complete', $booking->fresh()->status->value);
    }

    public function test_the_normal_sequential_complete_still_works(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->jobFor($driver, BookingStatus::Collected->value);

        $this->actingAs($driver)->post(route('driver.job.status', $booking), [
            'status' => 'complete', 'lat' => 53.35, 'lng' => -1.47, 'accuracy' => 20,
        ])->assertRedirect();

        $this->assertSame('complete', $booking->fresh()->status->value);
    }

    public function test_completing_without_location_goes_through_and_is_flagged(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->jobFor($driver, BookingStatus::EnRoute->value);

        // No lat/lng → the driver is NEVER blocked. The job completes, and the
        // office gets a flag that the mark came in without a GPS fix.
        $this->actingAs($driver)->post(route('driver.job.status', $booking), [
            'status' => 'complete',
        ])->assertRedirect();

        $this->assertSame('complete', $booking->fresh()->status->value);
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id,
            'event_type' => 'location_blocked',
        ]);
    }
}
