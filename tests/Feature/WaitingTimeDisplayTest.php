<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Waiting time booked on a job (the "Waiting time on this job" tick-box) must be
 * visible up front: as a headline chip on the office booking page, and on the
 * driver's job link so the driver knows to wait.
 */
class WaitingTimeDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function waitingBooking(): Booking
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'pickup_at' => now()->addHours(3),
            'passengers' => 2,
        ]);
        $booking->forceFill([
            'meta' => array_merge($booking->meta ?? [], [
                'waiting_time' => ['minutes' => 30, 'where' => 'stop'],
            ]),
        ])->save();

        return $booking->fresh();
    }

    public function test_the_booking_page_shows_the_waiting_time_chip(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->waitingBooking();

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Waiting 30 min at a stop');
    }

    public function test_the_driver_link_shows_the_waiting_time(): void
    {
        $booking = $this->waitingBooking();

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('Wait 30 min at a stop');
    }
}
