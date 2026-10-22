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
 * A driver can finish the job even if an intermediate tap (e.g. "Passenger on
 * board") was missed — but only from the drop-off. Complete is hard-gated: with no
 * GPS fix (or miles away) it's blocked and the office flagged, with no override.
 * When CET can't geocode the drop-off the check fails OPEN so a genuine completion
 * is never stuck.
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

    public function test_completing_without_location_stays_blocked_even_with_override(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->jobFor($driver, BookingStatus::EnRoute->value);

        // No lat/lng → Complete is hard-gated, so the override flag is ignored: a
        // driver with no GPS fix can't close the job, and the office is flagged.
        $this->actingAs($driver)->post(route('driver.job.status', $booking), [
            'status' => 'complete', 'override' => 1,
        ])->assertRedirect()->assertSessionHas('arriveError');

        $this->assertSame('en_route', $booking->fresh()->status->value);
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id,
            'event_type' => 'location_blocked',
        ]);
    }
}
