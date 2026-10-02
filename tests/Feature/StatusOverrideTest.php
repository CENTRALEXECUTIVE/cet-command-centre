<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin manual status override — set a booking to ANY stage, including winding a
 * completed/terminal job back (re-open), from the booking page.
 */
class StatusOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_reopen_a_completed_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Complete->value]);

        $this->actingAs($admin)->post(route('despatch.quick-status', $booking), ['status' => 'pending'])
            ->assertRedirect();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_the_status_controls_show_on_a_terminal_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::NoShow->value]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Update status')->assertSee('On Board (POB)');
    }

    public function test_a_driver_cannot_override_status(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = Booking::factory()->create(['status' => BookingStatus::Complete->value]);

        $this->actingAs($driver)->post(route('despatch.quick-status', $booking), ['status' => 'pending'])
            ->assertForbidden();
    }
}
