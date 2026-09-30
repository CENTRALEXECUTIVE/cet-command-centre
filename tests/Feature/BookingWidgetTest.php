<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\CorporateContact;
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
            ->assertJsonPath('price', 105)
            ->assertJsonPath('formatted', '£105');
    }

    public function test_it_validates_the_inputs(): void
    {
        $this->postJson(route('widget.price'), ['pickup' => 'Sheffield'])
            ->assertStatus(422);
    }

    public function test_the_full_booking_page_is_public(): void
    {
        $this->get(route('widget.book'))->assertOk()
            ->assertSee('Book now')
            ->assertSee('Payment method');
    }

    public function test_a_web_booking_lands_as_a_pending_request_and_alerts_the_office(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $res = $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
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

    public function test_via_stops_added_on_the_form_are_stored_on_the_booking(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'Via Tester', 'customer_phone' => '07464905385',
            'stops' => ['10 Ecclesall Road, Sheffield', '', '  Meadowhall, Sheffield  '],
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertNotNull($booking);
        // Empty rows are dropped; the rest are trimmed and kept in order.
        $this->assertSame(['10 Ecclesall Road, Sheffield', 'Meadowhall, Sheffield'], $booking->meta['stops']);
        $this->assertSame(2, (int) $booking->meta['extra_stops']);
        // Booking::viaStops() reads them so the driver/calendar sees the stops.
        $this->assertSame(['10 Ecclesall Road, Sheffield', 'Meadowhall, Sheffield'], $booking->viaStops());
    }

    private function makeAccount(string $contactEmail = 'jcraven@meps.co.uk'): CorporateAccount
    {
        $account = CorporateAccount::create([
            'name' => 'MEPS International Ltd', 'account_code' => '1001',
            'slug' => 'meps-international', 'is_active' => true, 'payment_terms_days' => 30,
        ]);
        CorporateContact::create([
            'corporate_account_id' => $account->id, 'name' => 'Jayne Craven',
            'email' => $contactEmail, 'is_primary' => true,
        ]);

        return $account;
    }

    public function test_account_check_recognises_a_business_contact_email(): void
    {
        $this->makeAccount();

        $this->getJson(route('widget.account-check', ['email' => 'JCraven@meps.co.uk']))
            ->assertOk()->assertJson(['account' => true, 'name' => 'MEPS International Ltd']);

        $this->getJson(route('widget.account-check', ['email' => 'random@nowhere.com']))
            ->assertOk()->assertJson(['account' => false]);
    }

    private function accountCustomer(CorporateAccount $account): \App\Models\Customer
    {
        return \App\Models\Customer::create([
            'name' => 'Jayne Craven', 'email' => 'jcraven@meps.co.uk',
            'phone' => '07700900123', 'corporate_account_id' => $account->id,
        ]);
    }

    public function test_a_signed_in_account_can_book_on_account_with_no_payment(): void
    {
        $account = $this->makeAccount();
        $customer = $this->accountCustomer($account);
        $executive = VehicleType::where('slug', 'executive')->first();

        // Signed in this session (as My Account / widget login would set).
        $this->withSession(['widget_customer_id' => $customer->id])
            ->post(route('widget.book.store'), [
                'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
                'destination_address' => 'Manchester Airport',
                'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'vehicle_type_id' => $executive->id, 'passengers' => 2,
                'customer_name' => 'Jayne Craven', 'customer_email' => 'jcraven@meps.co.uk',
                'payment_method' => 'account',
            ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertNotNull($booking);
        $this->assertSame(\App\Enums\PaymentMethod::Account, $booking->payment_method);
        $this->assertSame('pending', $booking->payment_status);
        $this->assertSame($account->id, (int) $booking->corporate_account_id);
        $this->assertTrue((bool) ($booking->meta['account_booking'] ?? false));
    }

    public function test_account_payment_is_refused_when_not_signed_in(): void
    {
        $this->makeAccount();
        $executive = VehicleType::where('slug', 'executive')->first();

        // Right email, but NOT signed in — account payment must not be honoured.
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'Jayne Craven', 'customer_email' => 'jcraven@meps.co.uk',
            'payment_method' => 'account',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertNotSame(\App\Enums\PaymentMethod::Account, $booking->payment_method);
        $this->assertNull($booking->corporate_account_id);
    }

    public function test_a_guest_can_create_an_account_at_checkout(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'New Customer', 'customer_email' => 'newbie@example.com',
            'create_account' => '1', 'password' => 'secret123',
        ])->assertOk();

        $customer = \App\Models\Customer::where('email', 'newbie@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertTrue($customer->checkPassword('secret123'));
    }

    public function test_widget_login_succeeds_and_reports_the_account(): void
    {
        $account = $this->makeAccount();
        $customer = $this->accountCustomer($account);
        $customer->setLoginPassword('secret123');

        $this->postJson(route('widget.login'), ['email' => 'jcraven@meps.co.uk', 'password' => 'secret123'])
            ->assertOk()->assertJson(['ok' => true, 'account' => ['name' => 'MEPS International Ltd']]);

        $this->postJson(route('widget.login'), ['email' => 'jcraven@meps.co.uk', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_a_flight_number_is_required_for_an_airport_return(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        // Return touching an airport, no flight number → rejected.
        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'No Flight', 'customer_phone' => '07464905385',
        ])->assertSessionHasErrors('flight_number');
    }

    public function test_a_flight_number_is_not_required_going_to_the_airport_one_way(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        // One-way TO the airport (a departure) needs no flight number.
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'To Airport', 'customer_phone' => '07464905385',
        ])->assertOk();
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
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'Return Rita', 'customer_phone' => '07464905385',
            'flight_number' => 'BA1368', // airport return — flight + landing required
            'flight_landing_at' => now()->addDay()->addHours(2)->format('Y-m-d\TH:i'),
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
        // Airport return: meet & greet is applied no matter what, and the flight
        // landing time is captured for the office.
        $this->assertTrue((bool) $out->meta['meet_greet']);
        $this->assertNotEmpty($out->meta['flight_landing_at']);
    }

    public function test_an_airport_return_requires_a_flight_landing_time(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        // Return touching an airport with a flight number but NO landing time → rejected.
        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'customer_name' => 'No Landing', 'customer_phone' => '07464905385',
            'flight_number' => 'BA1368',
        ])->assertSessionHasErrors('flight_landing_at');

        $this->assertSame(0, \App\Models\Booking::count());
    }

    public function test_a_web_widget_booking_emails_the_office(): void
    {
        // The office is emailed for every widget request — paid or not — so they
        // are aware of the booking and can chase up an unpaid one.
        \Illuminate\Support\Facades\Mail::fake();
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'Office Notice', 'customer_phone' => '07464905385',
        ])->assertOk();

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\OfficeBookingMail::class,
            fn ($m) => $m->hasTo(config('cet.ops_email'))
        );
    }

    public function test_a_return_journey_cannot_be_booked_as_cash(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'Cash Return', 'customer_phone' => '07464905385',
            'payment_method' => 'cash',
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, \App\Models\Booking::count());
    }

    public function test_a_return_booking_is_stored_as_a_card_job_not_cash(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'Card Return', 'customer_phone' => '07464905385',
            'payment_method' => 'card',
        ])->assertOk();

        $out = \App\Models\Booking::where('is_return_leg', false)->where('source', 'web')->first();
        $ret = \App\Models\Booking::where('is_return_leg', true)->first();
        $this->assertSame('card', $out->payment_method?->value);
        $this->assertSame('card', $ret->payment_method?->value);
    }

    public function test_a_sheffield_to_manchester_airport_executive_is_the_fixed_price(): void
    {
        // Regression: keeping the chosen airport address (not rewriting it to the
        // street name) means the fixed-price rule applies — £110, not distance pricing.
        $exec = VehicleType::where('slug', 'executive')->first();
        $res = $this->postJson(route('widget.prices'), [
            'pickup' => '9 Harney Close, Darnall, Sheffield, S9 5BW',
            'destination' => 'Manchester Airport T2 West Multi Storey - P3, Melbourne Ave, Manchester, UK, M90 5PR',
        ])->assertOk();

        $execOption = collect($res->json('options'))->firstWhere('id', $exec->id);
        $this->assertSame(105.0, (float) $execOption['price']);
    }

    public function test_a_web_hourly_hire_booking_is_as_directed(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'journey_type' => 'hourly', 'hours' => 4,
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => null,
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
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',      // NOT an airport pickup
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
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
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
            ->assertSee('Standard Minibus')
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
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Leeds',
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
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $minibus->id, 'passengers' => 4,
            'suitcases' => 5, 'hand_luggage' => 3,
            'customer_name' => 'Lots of Bags', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('minibus-8-xl', $booking->vehicleType->slug);
    }

    public function test_a_web_booking_captures_extras_and_flight(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2, 'suitcases' => 1, 'hand_luggage' => 1,
            'flight_number' => 'ba1368', 'meet_greet' => 1, 'child_seats' => 2, 'stopovers' => 1,
            'customer_name' => 'Extras User', 'customer_phone' => '07464905385',
            'notes' => 'Please call on arrival',
        ])->assertOk();

        $b = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('BA1368', $b->flight_number);
        $this->assertSame(2, $b->meta['child_seats']);
        $this->assertTrue((bool) $b->meta['meet_greet']);
        $this->assertStringContainsString('Meet & greet', (string) $b->special_requests);
        $this->assertStringContainsString('2× child seats', (string) $b->special_requests);
        $this->assertStringContainsString('Please call on arrival', (string) $b->special_requests);
    }

    public function test_a_vat_invoice_request_routes_billing_to_transfers(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $base = [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'customer_name' => 'VAT User', 'customer_phone' => '07464905385',
        ];

        // Ticked → Central Executive Transfers.
        $this->post(route('widget.book.store'), $base + ['vat_invoice' => 1])->assertOk();
        $b = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertTrue((bool) $b->meta['vat_invoice_requested']);
        $this->assertSame('transfers', $b->billingEntity());

        \App\Models\Booking::query()->delete();

        // Unticked → sister company Central Executive Chauffeurs (when configured).
        config([
            'services.square_chauffeurs.access_token' => 'CHAUFFEURS_TOKEN',
            'services.square_chauffeurs.location_id' => 'CHAUFFEURS_LOC',
        ]);
        $this->post(route('widget.book.store'), $base)->assertOk();
        $b2 = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertFalse((bool) ($b2->meta['vat_invoice_requested'] ?? false));
        $this->assertSame('chauffeurs', $b2->billingEntity());
    }

    public function test_a_vat_invoice_booking_is_stored_as_card_not_cash(): void
    {
        // "VAT bookings are always card" — even a one-way, and even if a cash choice
        // slips through from a direct POST, the driver never collects cash on a VAT job.
        $exec = VehicleType::where('slug', 'executive')->first();
        $base = [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'customer_name' => 'VAT User', 'customer_phone' => '07464905385',
            'vat_invoice' => 1,
        ];

        // Even with an explicit cash choice, a VAT booking is stored as card.
        $this->post(route('widget.book.store'), $base + ['payment_method' => 'cash'])->assertOk();
        $b = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('card', $b->payment_method->value);
        $this->assertTrue($b->vatInvoiceRequested());
        $this->assertFalse($b->isCashCollectJob());
    }

    public function test_a_plain_one_way_without_vat_still_defaults_to_cash(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 1AA',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'customer_name' => 'Cash User', 'customer_phone' => '07464905385',
        ])->assertOk();
        $b = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('cash', $b->payment_method->value);
        $this->assertTrue($b->isCashCollectJob());
        $this->assertFalse($b->vatInvoiceRequested());
    }

    public function test_booking_for_someone_else_makes_them_the_lead_passenger(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 1,
            'customer_name' => 'The Booker', 'customer_phone' => '07464905385',
            'booking_for_other' => 1, 'lead_passenger_name' => 'Jane Passenger', 'lead_passenger_phone' => '07999888777',
        ])->assertOk();

        $b = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('Jane Passenger', $b->meta['lead_name']);
        $this->assertSame('Jane Passenger', $b->displayName());
        $this->assertStringContainsString('Booked by The Booker', (string) $b->special_requests);
    }

    public function test_child_seats_are_capped_at_two_in_total(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 4,
            'customer_name' => 'Seat Family', 'customer_phone' => '07464905385',
            'child_seats' => 2, 'booster_seats' => 2, 'infant_seats' => 2, // 6 → capped to 2
        ])->assertOk();

        $b = \App\Models\Booking::firstWhere('source', 'web');
        $total = (int) ($b->meta['child_seats'] ?? 0) + (int) ($b->meta['booster_seats'] ?? 0) + (int) ($b->meta['infant_seats'] ?? 0);
        $this->assertSame(2, $total);
    }

    public function test_a_booking_without_a_postcode_is_accepted(): void
    {
        // Postcode is optional (airports/venues have none) — a booking without one
        // must go through, not be blocked.
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Manchester Airport (MAN)', // an airport, no postcode
            'destination_address' => 'Sheffield S1 2HH',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 1,
            'customer_name' => 'No Postcode', 'customer_phone' => '07464905385',
            'flight_number' => 'BA1368', 'flight_landing_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertOk();

        $this->assertSame(1, \App\Models\Booking::where('source', 'web')->count());
    }

    public function test_an_on_request_vehicle_lands_as_a_quote_request(): void
    {
        // Rolls Royce (POA) has no online price and no payment method — it's a
        // "request a quote": it still lands as a pending booking, flagged so the
        // office knows to email a price back, and the thanks page says so.
        $rolls = VehicleType::where('slug', 'rolls-royce-ghost')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Chatsworth House',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $rolls->id, 'passengers' => 2,
            'customer_name' => 'Wants A Quote', 'customer_phone' => '07464905385',
            'customer_email' => 'quote@example.com',
            // No payment_method sent — the widget hides it for a quote request.
        ])->assertOk()->assertSee('Quote request received');

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertNotNull($booking);
        $this->assertTrue((bool) ($booking->meta['quote_request'] ?? false));
        $this->assertNull($booking->quoted_price);
    }

    public function test_a_normal_minibus_party_stays_a_standard_minibus(): void
    {
        $minibus = VehicleType::where('slug', 'minibus-8')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Leeds',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $minibus->id, 'passengers' => 6,
            'suitcases' => 2, 'hand_luggage' => 2,
            'customer_name' => 'Normal Group', 'customer_phone' => '07464905385',
        ])->assertOk();

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertSame('minibus-8', $booking->vehicleType->slug);
    }
}
