<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The trash: an admin can see deleted bookings, restore them, or purge permanently.
 */
class BookingTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trash_lists_deleted_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['reference' => 'CET-TRASH1']);
        $booking->delete();

        $this->actingAs($admin)->get(route('bookings.trash'))->assertOk()
            ->assertSee('CET-TRASH1')->assertSee('Deleted bookings');
    }

    public function test_an_admin_can_restore_a_deleted_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();
        $booking->delete();

        $this->actingAs($admin)->post(route('bookings.restore', $booking->id))->assertRedirect();

        $this->assertNull(Booking::withTrashed()->find($booking->id)->deleted_at);
    }

    public function test_an_admin_can_permanently_delete_from_trash(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();
        $booking->delete();

        $this->actingAs($admin)->delete(route('bookings.force-destroy', $booking->id))->assertRedirect();

        $this->assertNull(Booking::withTrashed()->find($booking->id)); // gone for good
    }

    public function test_a_driver_cannot_use_the_trash(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = Booking::factory()->create();
        $booking->delete();

        $this->actingAs($driver)->get(route('bookings.trash'))->assertForbidden();
        $this->actingAs($driver)->post(route('bookings.restore', $booking->id))->assertForbidden();
    }
}
