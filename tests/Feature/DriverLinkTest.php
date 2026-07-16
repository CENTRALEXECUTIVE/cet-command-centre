<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shareable driver LINK: a cover driver works their job (view, status,
 * live GPS) with no account — the token in the URL is the key.
 */
class DriverLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_link_shows_the_job_without_a_login(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'pickup_address' => 'Manchester Airport (MAN), Terminal 1',
            'destination_address' => '5 Moorbridge Crescent, Barnsley',
            'payment_method' => PaymentMethod::Cash->value,
            'payment_status' => 'pending',
            'quoted_price' => 120.00,
            'final_price' => 120.00,
        ]);
        $token = $booking->driverLinkToken();

        $this->get(route('driver.link', $token))
            ->assertOk()
            ->assertSee('Manchester Airport (MAN), Terminal 1')
            ->assertSee('5 Moorbridge Crescent, Barnsley')
            ->assertSee('£120.00')                // cash to collect
            ->assertSee('to collect')
            ->assertSee('On My Way');             // a working status button
    }

    public function test_child_seats_are_shown_as_key_info(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'meta' => ['child_seats' => 2],
        ]);

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('Child seats')
            ->assertSee('2 child')
            ->assertSee('🚼');
    }

    public function test_an_unknown_or_finished_link_is_not_found(): void
    {
        $this->get(route('driver.link', 'nope-nope-nope'))->assertNotFound();

        $done = Booking::factory()->create(['status' => BookingStatus::Complete]);
        $this->get(route('driver.link', $done->driverLinkToken()))->assertNotFound();
    }

    public function test_a_driver_can_update_status_through_the_link(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Allocated]);
        $token = $booking->driverLinkToken();

        $this->post(route('driver.link.status', $token), ['status' => 'accepted'])
            ->assertRedirect();

        $this->assertSame(BookingStatus::Accepted, $booking->fresh()->status);
    }

    public function test_the_link_cannot_cancel_or_no_show(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Allocated]);
        $token = $booking->driverLinkToken();

        $this->post(route('driver.link.status', $token), ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');

        $this->assertSame(BookingStatus::Allocated, $booking->fresh()->status);
    }

    public function test_gps_pings_are_recorded_against_the_booking_only_while_driving(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Accepted]);
        $token = $booking->driverLinkToken();

        // Not driving yet (Accepted) → rejected.
        $this->postJson(route('driver.link.location', $token), ['lat' => 53.36, 'lng' => -2.27])
            ->assertOk()->assertJson(['recorded' => false]);
        $this->assertSame(0, DriverLocation::count());

        // Set off (En Route) → recorded, keyed to the booking, no user needed.
        $booking->forceFill(['status' => BookingStatus::EnRoute->value])->save();
        $this->postJson(route('driver.link.location', $token), ['lat' => 53.40, 'lng' => -1.50])
            ->assertOk()->assertJson(['recorded' => true]);

        $this->assertDatabaseHas('driver_locations', [
            'booking_id' => $booking->id, 'driver_id' => null,
        ]);
    }

    public function test_the_booking_page_offers_the_driver_link(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Allocated]);
        $token = $booking->driverLinkToken(); // mint it first so we can match it

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Driver link')
            ->assertSee($token, false);
    }
}
