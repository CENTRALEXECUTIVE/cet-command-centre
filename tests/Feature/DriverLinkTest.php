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
            ->assertSee('£120')                   // cash to collect
            ->assertSee('to collect')
            ->assertSee('On My Way');             // a working status button
    }

    public function test_the_link_shows_the_allow_location_gate(): void
    {
        // The link must be able to prompt for location — a tap-driven gate that
        // triggers the browser's native permission prompt.
        $booking = Booking::factory()->create(['status' => BookingStatus::Accepted]);

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('loc-gate')
            ->assertSee('Allow location')
            ->assertSee('Turn on location for this job');
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
            'passengers' => 4, // pin so the "implausible count" failsafe never fires
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
        // The booking's OWN count is authoritative (no calendar to defer to).
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted, 'passengers' => 4, 'meta' => ['child_seats' => 1],
        ]);
        $this->assertSame('1 child seat', $booking->displayChildSeats());

        // Corrected to a mix → proper singular/plural wording (valid for 4 pax).
        $booking->forceFill(['meta' => ['child_seats' => 2, 'booster_seats' => 1]])->save();
        $this->assertSame('2 child seats · 1 booster seat', $booking->fresh()->displayChildSeats());
    }

    public function test_the_link_pulls_the_live_calendar_when_opened(): void
    {
        // A booking whose SAVED copy says 1 child seat…
        $booking = Booking::factory()->create(['status' => BookingStatus::Accepted, 'passengers' => 3]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x', 'google_event_id' => 'evt-1',
            'description' => "📑 Booking Confirmation\n• *Child Seats:* 1",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        // …but the office has since changed the LIVE calendar to 2. Opening the
        // link syncs from the live calendar (mocked here) before rendering.
        $this->mock(\App\Services\Calendar\CalendarTimeSync::class, function ($m) {
            $m->shouldReceive('scan')->once()->andReturnUsing(function (Booking $b) {
                $b->calendarEvent->forceFill([
                    'description' => "📑 Booking Confirmation\n• *Child Seats:* 2",
                ])->save();

                return ['status' => 'ok', 'changes' => [], 'diag' => []];
            });
        });

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('🚼 2')
            ->assertDontSee('🚼 1');
    }

    public function test_calendar_child_seats_never_show_a_stray_markdown_asterisk(): void
    {
        // The calendar wraps labels in markdown bold: "• *Child Seats:* 3". The
        // closing "*" must NOT leak into the value — the driver must see "3",
        // never "* 3" (the "🚼 * 3" chip bug).
        $booking = Booking::factory()->create(['status' => BookingStatus::Accepted, 'passengers' => 3]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "📑 Booking Confirmation\n• *Child Seats:* 3\n• *Vehicle:* Minibus 8 Seater",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);
        $booking = $booking->fresh();

        $this->assertSame('3', $booking->calendarChildSeats());
        $this->assertSame('3', $booking->displayChildSeats());

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('🚼 3')
            ->assertDontSee('* 3');
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

    public function test_the_link_message_carries_only_name_and_datetime(): void
    {
        // Deliberately minimal: customer name + weekday/date/time (numbers) +
        // the link. No route, no flight — the driver finds the rest on the link.
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Allocated,
            'pickup_at' => \Illuminate\Support\Carbon::create(2026, 7, 24, 14, 30, 0, config('app.timezone')),
            'pickup_address' => '21 Ecclesall Rd, Sheffield',
            'destination_address' => 'Manchester Airport T2',
            'meta' => ['lead_name' => 'Jo Smith'],
        ]);

        $msg = $booking->driverLinkMessage();

        $this->assertStringContainsString('Jo Smith', $msg);                                 // customer name
        $this->assertStringContainsString($booking->pickup_at->format('D d/m/Y H:i'), $msg); // weekday + numbers
        $this->assertStringContainsString($booking->driverLinkUrl(), $msg);                  // the link
        // Route and drop-off are NOT in the message — they're behind the link.
        $this->assertStringNotContainsString('→', $msg);
        $this->assertStringNotContainsString('Manchester Airport', $msg);
    }
}
