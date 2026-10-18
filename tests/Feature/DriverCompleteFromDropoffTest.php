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
 * A driver at the drop-off can always finish the job — even if an intermediate
 * tap (e.g. "Passenger on board") was missed on a long job. The only rule is
 * being at the drop-off with location on.
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

    public function test_completing_still_needs_location_on(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->jobFor($driver, BookingStatus::EnRoute->value);

        // No lat/lng → blocked (we must see the driver), and the job stays open.
        $this->actingAs($driver)->post(route('driver.job.status', $booking), [
            'status' => 'complete',
        ])->assertRedirect();

        $this->assertSame(BookingStatus::EnRoute->value, $booking->fresh()->status->value);
    }
}
