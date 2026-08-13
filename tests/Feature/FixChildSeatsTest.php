<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scrubbing bad child-seat data (child_seats accidentally set to the passenger
 * count) and the write-time guard that stops it recurring.
 */
class FixChildSeatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_command_reconciles_a_bad_count_to_the_calendar(): void
    {
        $booking = Booking::factory()->create(['passengers' => 7]);
        $booking->forceFill(['meta' => ['child_seats' => 7]])->save();
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Child Seats / Booster Seats / Infant Seats: 🚼 1 Child Seat\n• Vehicle Type: Minibus",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $this->artisan('cet:fix-child-seats')->assertSuccessful();

        $this->assertSame(1, (int) $booking->fresh()->meta['child_seats']);
    }

    public function test_the_command_caps_an_impossible_count_without_a_calendar(): void
    {
        $booking = Booking::factory()->create(['passengers' => 4]);
        $booking->forceFill(['meta' => ['child_seats' => 99]])->save();

        $this->artisan('cet:fix-child-seats')->assertSuccessful();

        $this->assertSame(0, (int) $booking->fresh()->meta['child_seats']); // 99 >= pax, pax>=3 → zeroed
    }

    public function test_the_command_zeroes_the_passenger_leak(): void
    {
        // The exact bug: child_seats == passengers, no calendar to verify.
        $booking = Booking::factory()->create(['passengers' => 5]);
        $booking->forceFill(['meta' => ['child_seats' => 5]])->save();

        $this->artisan('cet:fix-child-seats')->assertSuccessful();

        $this->assertSame(0, (int) $booking->fresh()->meta['child_seats']);
    }

    public function test_the_command_leaves_a_sensible_count_alone(): void
    {
        $booking = Booking::factory()->create(['passengers' => 5]);
        $booking->forceFill(['meta' => ['child_seats' => 2]])->save();

        $this->artisan('cet:fix-child-seats')->assertSuccessful();

        $this->assertSame(2, (int) $booking->fresh()->meta['child_seats']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $booking = Booking::factory()->create(['passengers' => 6]);
        $booking->forceFill(['meta' => ['child_seats' => 6]])->save();

        $this->artisan('cet:fix-child-seats', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(6, (int) $booking->fresh()->meta['child_seats']);
    }

    public function test_editing_a_booking_cannot_store_more_seats_than_passengers(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['pickup_at' => now()->addDay(), 'passengers' => 3]);
        $vt = VehicleType::first();

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Test', 'customer_phone' => '07700900000',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport',
            'passengers' => 3, 'payment_method' => 'cash', 'journey_type' => 'one_way',
            'child_seats' => 7, // impossible for 3 passengers
        ])->assertRedirect();

        $this->assertSame(3, (int) $booking->fresh()->meta['child_seats']); // capped at passengers
    }
}
