<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A COMPLETED (or cancelled) booking must stay editable in place — the office
 * needs to correct a wrong date or name without reverting the status and losing
 * the job's timeline (status history). Editing fields never touches the history.
 */
class EditCompletedBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_edit_form_opens_for_a_completed_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Complete]);

        $this->actingAs($admin)->get(route('bookings.edit', $booking))
            ->assertOk()
            ->assertSee('Edit', false);
    }

    public function test_editing_a_completed_booking_keeps_its_status_and_timeline(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Complete,
            'vehicle_type_id' => $exec->id,
            'pickup_at' => Carbon::parse('2026-10-08 09:00'),
        ]);
        // A timeline entry that must survive the edit.
        $booking->statusHistory()->create([
            'from_status' => 'collected', 'to_status' => 'complete', 'created_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Janine Neill', 'customer_phone' => '07985454218',
            'journey_type' => 'one_way',
            'pickup_address' => 'Terminal 2, Manchester', 'destination_address' => '2 Worrygoose Lane, Rotherham',
            'pickup_at' => '2026-10-04T09:15',
            'vehicle_type_id' => $exec->id, 'passengers' => 1, 'payment_method' => 'card',
        ])->assertRedirect(route('bookings.show', $booking));

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Complete, $fresh->status);                 // status untouched
        $this->assertSame('2026-10-04 09:15', $fresh->pickup_at->format('Y-m-d H:i')); // date corrected
        $this->assertSame(1, $fresh->statusHistory()->count());                      // timeline intact
    }
}
