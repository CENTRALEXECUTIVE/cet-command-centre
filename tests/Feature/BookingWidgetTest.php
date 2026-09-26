<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public, embeddable mini web-booking widget (ETO-style): a customer checks a
 * price with no login, using the existing CET fixed-price/free-roam engine.
 */
class BookingWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_mini_widget_page_is_public_and_embeddable(): void
    {
        $res = $this->get(route('widget.mini'))->assertOk()
            ->assertSee('Get my price')
            ->assertSee('CENTRAL');

        // It must allow embedding on the marketing site (frame-ancestors set,
        // and no blanket X-Frame-Options DENY).
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', (string) $csp);
        $this->assertStringContainsString('centralexecutivetransfers.co.uk', (string) $csp);
        $this->assertNotSame('DENY', $res->headers->get('X-Frame-Options'));
    }

    public function test_it_returns_a_fixed_price_for_a_known_route(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->postJson(route('widget.price'), [
            'pickup' => 'Sheffield S1 2HH',
            'destination' => 'Manchester Airport',
            'vehicle_type_id' => $executive->id,
        ])->assertOk()
            ->assertJson(['fixed' => true, 'vehicle' => $executive->name])
            ->assertJsonPath('price', 110)
            ->assertJsonPath('formatted', '£110');
    }

    public function test_it_validates_the_inputs(): void
    {
        $this->postJson(route('widget.price'), ['pickup' => 'Sheffield'])
            ->assertStatus(422);
    }

    public function test_the_full_booking_page_is_public(): void
    {
        $this->get(route('widget.book'))->assertOk()
            ->assertSee('Request booking')
            ->assertSee('No payment is taken now');
    }

    public function test_a_web_booking_lands_as_a_pending_request_and_alerts_the_office(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $res = $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id,
            'passengers' => 2,
            'suitcases' => 1, 'hand_luggage' => 2,
            'customer_name' => 'Lloyd Oyefuwa',
            'customer_phone' => '07464905385',
            'notes' => 'Please call on arrival',
        ])->assertOk();

        $res->assertSee('Booking request received');

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertNotNull($booking);
        $this->assertSame(\App\Enums\BookingStatus::Pending, $booking->status);
        $this->assertSame('Manchester Airport', $booking->destination_address);
        $this->assertSame(2, (int) $booking->passengers);
        $this->assertSame('Lloyd Oyefuwa', $booking->customer->name);
        // Nothing auto-sent, calendar untouched — but the office is alerted.
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id, 'event_type' => 'web_booking',
        ]);
    }

    public function test_the_honeypot_blocks_spam_bookings(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'company' => 'spammer ltd', // honeypot filled
            'pickup_address' => 'A', 'destination_address' => 'B',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'Bot', 'customer_phone' => '07000000000',
        ])->assertOk();

        $this->assertSame(0, \App\Models\Booking::count());
    }

    public function test_mark_fare_paid_is_idempotent(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $booking = \App\Models\Booking::factory()->forVehicleType($executive)->create(['payment_status' => 'pending']);

        $this->assertTrue($booking->markFarePaid('sq_pay_1', 100.0));
        $this->assertSame('paid', $booking->fresh()->payment_status);
        // Same Square payment id again → no double record.
        $this->assertFalse($booking->fresh()->markFarePaid('sq_pay_1', 100.0));
    }

    public function test_the_pay_link_must_be_signed(): void
    {
        $booking = \App\Models\Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['quoted_price' => 100]);

        // Unsigned → rejected.
        $this->get(route('widget.pay', $booking))->assertForbidden();

        // Signed, but Square isn't configured in tests → graceful "confirm later".
        $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute('widget.pay', now()->addHour(), ['booking' => $booking->id]);
        $this->get($signed)->assertRedirect(route('widget.paid', ['unavailable' => 1]));
    }

    public function test_a_web_booking_requires_contact_and_a_future_time(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'A', 'destination_address' => 'B',
            'pickup_at' => now()->subDay()->format('Y-m-d\TH:i'), // past
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'No Contact', // no phone or email
        ])->assertSessionHasErrors(['pickup_at', 'customer_phone']);

        $this->assertSame(0, \App\Models\Booking::count());
    }

    public function test_a_web_return_booking_creates_two_linked_legs(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'Return Rita', 'customer_phone' => '07464905385',
        ])->assertOk();

        $out = \App\Models\Booking::where('is_return_leg', false)->where('source', 'web')->first();
        $ret = \App\Models\Booking::where('is_return_leg', true)->first();
        $this->assertNotNull($ret);
        $this->assertSame($ret->id, $out->linked_booking_id);
        $this->assertSame($out->id, $ret->linked_booking_id);
        // Return leg reverses the route; fare stays on the outbound only.
        $this->assertSame('Manchester Airport', $ret->pickup_address);
        $this->assertSame('Sheffield S1 2HH', $ret->destination_address);
        $this->assertNull($ret->quoted_price);
    }

    public function test_a_web_hourly_hire_booking_is_as_directed(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'journey_type' => 'hourly', 'hours' => 4,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => null,
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'Hourly Hugh', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertTrue($booking->isHourlyHire());
        $this->assertSame(4, $booking->hourlyHours());
        $this->assertSame('As directed (hourly hire)', $booking->destination_address);
    }

    public function test_a_web_booking_defaults_to_cash_so_the_driver_collects(): void
    {
        // A web booking takes no card up front, so the customer pays on the day
        // and the DRIVER collects. Stamping it 'card' told the driver "collect
        // nothing" and lost the fare — regression guard.
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH',      // NOT an airport pickup
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'Cash Carl', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('cash', $booking->payment_method?->value);

        // With a fare, the driver link tells them to collect it — never "nothing".
        $booking->forceFill(['final_price' => 120])->save();
        $line = (string) $booking->fresh()->driverCollectLine();
        $this->assertStringContainsString('collect (cash)', $line);
        $this->assertStringNotContainsString('collect nothing', $line);
    }

    public function test_a_web_fare_paid_online_via_square_is_not_collected_again(): void
    {
        // If the customer DOES pay online, Square records it and the driver must
        // not also collect cash — that would double-charge. The business holds the
        // money and pays the driver via payroll instead.
        $executive = VehicleType::where('slug', 'executive')->first();
        $booking = \App\Models\Booking::factory()->forVehicleType($executive)->create([
            'source' => 'web',
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'final_price' => 120,
        ]);

        $booking->markFarePaid('sq_fare_1', 120.0);
        $booking->refresh();

        $this->assertTrue($booking->businessCollectedCash());
        $this->assertNull($booking->cashDueToDriver());
        $this->assertStringContainsString('collect nothing', (string) $booking->driverCollectLine());
    }

    public function test_minibus_xl_card_is_present_but_hidden_until_needed(): void
    {
        // The XL card is rendered so its price is ready, but starts hidden — it's
        // only revealed client-side when the party/luggage outgrows the 8-Seater.
        $html = $this->get(route('widget.book'))->assertOk()
            ->assertSee('Minibus 8 Seater')
            ->assertSee('data-slug="minibus-8-xl"', false)
            ->getContent();

        // The XL <label> carries the `hidden` attribute out of the box.
        $this->assertMatchesRegularExpression(
            '/data-slug="minibus-8-xl"[^>]*\shidden/',
            $html,
            'The Minibus XL card should be hidden by default.'
        );
    }

    public function test_the_bulk_prices_endpoint_returns_a_price_per_vehicle(): void
    {
        $res = $this->postJson(route('widget.prices'), [
            'pickup' => 'Sheffield S1 2HH',
            'destination' => 'Manchester Airport',
        ])->assertOk();

        $options = $res->json('options');
        $this->assertNotEmpty($options);
        // Every active vehicle is priced (a figure or "On request"), keyed by id.
        foreach ($options as $o) {
            $this->assertArrayHasKey('id', $o);
            $this->assertArrayHasKey('formatted', $o);
        }
    }

    public function test_a_large_minibus_party_is_auto_upgraded_to_xl(): void
    {
        $minibus = VehicleType::where('slug', 'minibus-8')->first();

        // 8 passengers is beyond the standard Minibus (7) — upgrade to XL.
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $minibus->id, 'passengers' => 8,
            'suitcases' => 0, 'hand_luggage' => 0,
            'customer_name' => 'Big Group', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('minibus-8-xl', $booking->vehicleType->slug);
    }

    public function test_a_heavy_luggage_minibus_is_auto_upgraded_to_xl(): void
    {
        $minibus = VehicleType::where('slug', 'minibus-8')->first();

        // Within passenger limit but too many bags for the standard Minibus (5).
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $minibus->id, 'passengers' => 4,
            'suitcases' => 5, 'hand_luggage' => 3,
            'customer_name' => 'Lots of Bags', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('minibus-8-xl', $booking->vehicleType->slug);
    }

    public function test_a_normal_minibus_party_stays_a_standard_minibus(): void
    {
        $minibus = VehicleType::where('slug', 'minibus-8')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $minibus->id, 'passengers' => 6,
            'suitcases' => 2, 'hand_luggage' => 2,
            'customer_name' => 'Normal Group', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('minibus-8', $booking->vehicleType->slug);
    }
}
