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

    public function test_the_token_resolves_via_the_column_and_a_legacy_meta_token(): void
    {
        // New booking → token lands on the indexed column.
        $booking = Booking::factory()->create(['status' => BookingStatus::Allocated]);
        $token = $booking->driverLinkToken();
        $this->assertSame($token, $booking->fresh()->driver_link_token);
        $this->assertTrue(Booking::byDriverLinkToken($token)?->is($booking));

        // A link sent before the column existed (token only in meta) still works.
        $legacy = Booking::factory()->create(['status' => BookingStatus::Allocated]);
        $legacy->forceFill(['driver_link_token' => null, 'meta' => ['driver_link_token' => 'legacy-abc-123']])->save();
        $this->assertTrue(Booking::byDriverLinkToken('legacy-abc-123')?->is($legacy));
        $this->get(route('driver.link', 'legacy-abc-123'))->assertOk();
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

    public function test_child_seat_count_is_faithful_and_correctable(): void
    {
        // The booking's OWN count is authoritative and wins over a stale import.
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted, 'meta' => ['child_seats' => 1],
        ]);
        $this->assertSame('1 child seat', $booking->displayChildSeats());

        // Corrected to a mix → proper singular/plural wording.
        $booking->forceFill(['meta' => ['child_seats' => 2, 'booster_seats' => 1]])->save();
        $this->assertSame('2 child seats · 1 booster seat', $booking->fresh()->displayChildSeats());
    }

    public function test_bad_and_finished_links_show_a_branded_message_not_a_crash(): void
    {
        // Unknown token → a tidy "not active" page, never a raw 404 or crash.
        $this->get(route('driver.link', 'nope-nope-nope'))
            ->assertOk()->assertSee('isn’t active', false);

        // A completed job's link closes cleanly (no details)…
        $done = Booking::factory()->create(['status' => BookingStatus::Complete]);
        $this->get(route('driver.link', $done->driverLinkToken()))
            ->assertOk()
            ->assertSee('this link is now closed')
            ->assertDontSee('On My Way');

        // …and neither can be updated.
        $this->post(route('driver.link.status', $done->driverLinkToken()), ['status' => 'en_route'])
            ->assertNotFound();
        $this->post(route('driver.link.status', 'nope-nope-nope'), ['status' => 'en_route'])
            ->assertNotFound();
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

    public function test_the_link_message_carries_a_few_key_details(): void
    {
        // Maj's ask: date & time (so they don't forget) + route + passenger,
        // then the link — a short reminder, not the whole sheet.
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Allocated,
            'pickup_at' => \Illuminate\Support\Carbon::create(2026, 7, 24, 14, 30, 0, config('app.timezone')),
            'pickup_address' => '21 Ecclesall Rd, Sheffield',
            'destination_address' => 'Manchester Airport T2',
            'meta' => ['lead_name' => 'Jo Smith'],
        ]);

        $msg = $booking->driverLinkMessage();

        $this->assertStringContainsString('24/07/2026', $msg);        // date
        $this->assertStringContainsString('14:30', $msg);             // time
        $this->assertStringContainsString('21 Ecclesall Rd, Sheffield → Manchester Airport T2', $msg);
        $this->assertStringContainsString('Jo Smith', $msg);          // passenger
        $this->assertStringContainsString($booking->driverLinkUrl(), $msg); // the link
    }
}
