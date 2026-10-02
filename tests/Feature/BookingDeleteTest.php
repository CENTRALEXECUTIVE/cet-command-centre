<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admins can delete a booking — it's removed from the Command Centre (soft delete,
 * recoverable), the Google Calendar is untouched, and a return trip's linked leg goes
 * with it. Drivers cannot delete.
 */
class BookingDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_an_admin_can_delete_a_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();

        $this->actingAs($admin)->delete(route('bookings.destroy', $booking))
            ->assertRedirect(route('bookings.index'))->assertSessionHas('status');

        // Gone from normal queries, but soft-deleted (recoverable).
        $this->assertNull(Booking::find($booking->id));
        $this->assertNotNull(Booking::withTrashed()->find($booking->id)->deleted_at);
    }

    public function test_deleting_a_return_removes_both_legs(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $out = Booking::factory()->forVehicleType($exec)->create(['journey_type' => 'return']);
        $return = Booking::factory()->forVehicleType($exec)->create(['journey_type' => 'return', 'is_return_leg' => true, 'linked_booking_id' => $out->id]);
        $out->forceFill(['linked_booking_id' => $return->id])->save();

        $this->actingAs($admin)->delete(route('bookings.destroy', $out))->assertRedirect();

        $this->assertNull(Booking::find($out->id));
        $this->assertNull(Booking::find($return->id));
    }

    public function test_the_delete_button_shows_on_the_booking_page(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Delete booking');
    }

    public function test_a_driver_cannot_delete_a_booking(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = Booking::factory()->create();

        $this->actingAs($driver)->delete(route('bookings.destroy', $booking))->assertForbidden();
        $this->assertNotNull(Booking::find($booking->id));
    }
}
