<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meet & greet ONLY ever applies to a leg that COLLECTS from an airport (an
 * arrival) — never a drop-off to one. Oct 2026: 28a Park Drive, Sprotbrough,
 * Doncaster → East Midlands Airport showed £190 (£180 + £10 meet & greet). These
 * cover every other path the same class of bug could take: the customer widget,
 * the public /book page, the quote engine, the email preview, imports, the
 * return-leg button, and what the driver sheet / calendar / emails show.
 */
class MeetGreetArrivalOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const HOME = '28a Park Drive, Sprotbrough, Doncaster DN5 7LA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
        config(['cet.surcharges.meet_greet' => 10]);
    }

    private function exec(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->firstOrFail();
    }

    // --- Customer widget ------------------------------------------------------

    public function test_widget_one_way_drop_off_with_meet_greet_ticked_is_not_charged(): void
    {
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Manchester Airport (MAN)',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $this->exec()->id, 'passengers' => 1,
            'customer_name' => 'Ticked Tom', 'customer_phone' => '07464905385',
            'meet_greet' => 1, // ticked — but this is a departure
        ])->assertOk();

        $b = Booking::firstWhere('source', 'web');
        $this->assertEqualsWithDelta(105.0, (float) $b->quoted_price, 0.01); // not £115
        $this->assertFalse((bool) ($b->meta['meet_greet'] ?? false));
        $this->assertArrayNotHasKey('fare_extras_total', $b->meta);
        $this->assertStringNotContainsString('Meet & greet', (string) $b->special_requests);
    }

    public function test_widget_luton_town_pickup_is_not_an_airport_arrival(): void
    {
        // The widget's old regex matched bare "luton": a Luton home pickup was forced
        // into meet & greet AND a mandatory flight number + landing time.
        $this->post(route('widget.book.store'), [
            'pickup_address' => '10 George Street, Luton LU1 2AF', 'destination_address' => 'Sheffield S1 2HH',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $this->exec()->id, 'passengers' => 1,
            'customer_name' => 'Luton Lou', 'customer_phone' => '07464905385',
        ])->assertOk()->assertSessionHasNoErrors();

        $b = Booking::firstWhere('source', 'web');
        $this->assertNotNull($b);
        $this->assertFalse((bool) ($b->meta['meet_greet'] ?? false));
    }

    public function test_widget_return_puts_meet_greet_on_the_arrival_leg_only_when_it_is_the_outbound(): void
    {
        // Fly IN first: MAN → Sheffield, then Sheffield → MAN. The outbound collects
        // from the airport, so IT carries meet & greet; the return drop-off doesn't.
        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield S1 2HH',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $this->exec()->id, 'passengers' => 1,
            'customer_name' => 'Inbound Ida', 'customer_phone' => '07464905385',
            'flight_number' => 'BA1368', 'flight_landing_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'payment_method' => 'card',
        ])->assertOk();

        $out = Booking::where('source', 'web')->where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertEqualsWithDelta(115.0, (float) $out->quoted_price, 0.01);
        $this->assertEqualsWithDelta(105.0, (float) $ret->quoted_price, 0.01);
        $this->assertTrue($out->isMeetGreet());
        $this->assertFalse($ret->isMeetGreet());
        $this->assertFalse((bool) ($ret->meta['meet_greet'] ?? false));
        $this->assertStringNotContainsString('Meet & greet', (string) $ret->special_requests);
    }

    // --- Public /book page and the quote engine --------------------------------

    public function test_public_book_never_charges_meet_greet_on_a_drop_off(): void
    {
        $payload = [
            'pickup_postcode' => 'S1 2HH',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $this->exec()->id, 'passengers' => 1,
            'customer_name' => 'Jane Traveller', 'customer_email' => 'jane@example.com',
            'customer_phone' => '07700900123',
            'meet_greet' => 1,
        ];

        $this->post(route('public.book.store'), $payload + [
            'pickup_address' => '10 Division Street, Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
        ])->assertRedirect();
        $this->assertSame(105.0, (float) Booking::latest('id')->first()->quoted_price);

        // From the airport it IS an arrival — charged.
        $this->post(route('public.book.store'), array_merge($payload, [
            'pickup_address' => 'Manchester Airport (MAN)', 'pickup_postcode' => 'M90 1QX',
            'destination_address' => '10 Division Street, Sheffield S1 2HH',
        ]))->assertRedirect();
        $arrival = Booking::latest('id')->first();
        $this->assertContains('Meet & greet', array_column($arrival->meta['fare_extras'] ?? [], 'label'));
    }

    // --- Admin create-form email preview ---------------------------------------

    public function test_admin_email_preview_does_not_show_meet_greet_for_a_doncaster_home_pickup(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->post(route('bookings.confirmation-email.preview-new'), [
            'customer_name' => 'Sprotbrough Sam',
            'pickup_address' => self::HOME,
            'destination_address' => 'East Midlands Airport (EMA)',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'journey_type' => 'one_way',
            'quoted_price' => 180,
            'vehicle_type_id' => $this->exec()->id,
            'meet_greet' => 1,
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString('Meet &amp; greet', $html);
    }

    // --- Saved data: driver sheet, calendar, emails -----------------------------

    private function departureWithStaleFlag(array $meta = []): Booking
    {
        return Booking::factory()->forVehicleType($this->exec())->create([
            'customer_id' => Customer::create(['name' => 'Stale Flag'])->id,
            'airport_id' => null,
            'pickup_address' => self::HOME,
            'destination_address' => 'East Midlands Airport (EMA)',
            'meta' => array_merge(['meet_greet' => true, 'meet_and_greet' => true], $meta),
        ]);
    }

    public function test_a_stale_meet_greet_flag_never_shows_on_a_departure_leg(): void
    {
        $b = $this->departureWithStaleFlag(['journey_label' => 'Departure (Meet & Greet)']);

        $this->assertFalse($b->isMeetGreet());
        $this->assertNull($b->displayMeetAndGreet());
        $this->assertNull($b->meetGreetLabel());

        $preview = app(CalendarEventBuilder::class)->preview($b);
        $this->assertStringNotContainsString('Meet & Greet', $preview['description']);
        $this->assertStringContainsString('Booking Confirmation – Departure', $preview['description']);
        $this->assertStringNotContainsString('Arrival', $preview['title']);

        $html = (new BookingConfirmationMail($b->fresh(), paid: false))->render();
        $this->assertStringNotContainsString('Meet &amp; greet:', $html);
    }

    public function test_an_old_calendar_meet_greet_line_on_a_drop_off_is_not_shown_to_the_driver(): void
    {
        // Calendar events written before the fix may carry "Meet & Greet" on a
        // departure; the driver sheet / booking page must not repeat it.
        $b = $this->departureWithStaleFlag();
        $b->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => self::HOME,
            'description' => '• Pickup Location: '.self::HOME."\n• Drop-off Location: East Midlands Airport (EMA)\n• Meet & Greet: Required",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);
        $b = $b->fresh();
        $this->assertSame('Required', $b->calendarField('Meet & Greet')); // it IS on the event…
        $this->assertNull($b->displayMeetAndGreet());                    // …but never shown

        // The same line on a genuine arrival still shows.
        $arr = Booking::factory()->forVehicleType($this->exec())->create([
            'customer_id' => Customer::create(['name' => 'Cal Arrival'])->id,
            'pickup_address' => 'East Midlands Airport (EMA)', 'destination_address' => self::HOME,
        ]);
        $arr->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'EMA',
            'description' => "• Pickup Location: East Midlands Airport (EMA)\n• Meet & Greet: Required",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);
        $this->assertSame('Required', $arr->fresh()->displayMeetAndGreet());
    }

    public function test_the_flag_still_shows_on_a_genuine_arrival(): void
    {
        $b = Booking::factory()->forVehicleType($this->exec())->create([
            'customer_id' => Customer::create(['name' => 'Real Arrival'])->id,
            'airport_id' => null,
            'pickup_address' => 'East Midlands Airport (EMA)',
            'destination_address' => self::HOME,
            'meta' => ['meet_greet' => true],
        ]);

        $this->assertTrue($b->isMeetGreet());
        $this->assertStringContainsString('Meet & Greet', app(CalendarEventBuilder::class)->preview($b)['description']);
        $this->assertStringContainsString('Meet &amp; greet:', (new BookingConfirmationMail($b->fresh(), paid: false))->render());
    }

    public function test_an_airport_road_home_is_not_titled_an_arrival(): void
    {
        // "airport" in a street name used to make isAirportPickup() true.
        $b = Booking::factory()->forVehicleType($this->exec())->create([
            'customer_id' => Customer::create(['name' => 'Road Name'])->id,
            'airport_id' => null,
            'pickup_address' => '15 Airport Road, Doncaster DN9 3XX',
            'destination_address' => '12 Fargate, Sheffield',
            'meta' => ['meet_greet' => true],
        ]);

        $this->assertFalse($b->isAirportPickup());
        $this->assertFalse($b->isMeetGreet());
        $this->assertStringNotContainsString('Arrival', app(CalendarEventBuilder::class)->preview($b)['title']);
    }

    // --- Return-leg button ------------------------------------------------------

    public function test_return_leg_from_an_eto_arrival_drops_meet_and_greet_and_the_arrival_label(): void
    {
        $admin = User::factory()->admin()->create();
        $outbound = Booking::factory()->forVehicleType($this->exec())->create([
            'customer_id' => Customer::create(['name' => 'ETO Arrival'])->id,
            'airport_id' => null,
            'pickup_address' => 'East Midlands Airport (EMA)',
            'destination_address' => self::HOME,
            'linked_booking_id' => null,
            'is_return_leg' => false,
            'meta' => ['meet_and_greet' => true, 'journey_label' => 'Arrival (Meet & Greet)'],
        ]);

        $this->actingAs($admin)->post(route('bookings.return-leg', $outbound), [
            'return_pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertSame(self::HOME, $ret->pickup_address);
        $this->assertArrayNotHasKey('meet_and_greet', $ret->meta ?? []);
        $this->assertSame('Departure', $ret->meta['journey_label'] ?? null);
        $this->assertFalse($ret->isMeetGreet());
        $this->assertStringNotContainsString('Meet & Greet', app(CalendarEventBuilder::class)->preview($ret)['description']);
    }

    // --- ETO e-mail / calendar ingestion -----------------------------------------

    public function test_an_ingested_departure_never_stores_meet_and_greet(): void
    {
        app(OutlookBookingService::class)->upsertFromParsed([
            'is_booking' => true, 'cancelled' => false,
            'reference' => 'MGDEP1a',
            'customer_name' => 'Departing Doncaster', 'customer_phone' => '07985454218',
            'passengers' => 1, 'vehicle_type' => 'Executive',
            'payment_status' => 'paid', 'payment_method' => 'Square',
            'pickup_address' => self::HOME,
            'destination_address' => 'East Midlands Airport (EMA)',
            'pickup_at' => '2026-11-04 05:15',
            'meet_and_greet' => true, // ETO said yes — but it's a drop-off
            'payment_text' => 'Paid £180 (Square)', 'total_amount' => 180.0,
        ]);

        $b = Booking::where('external_reference', 'MGDEP1a')->firstOrFail();
        $this->assertFalse((bool) ($b->meta['meet_and_greet'] ?? false));
        $this->assertSame('Departure', $b->meta['journey_label']);
        $this->assertFalse($b->isMeetGreet());
    }

    // --- Paste-a-booking free-text parser ---------------------------------------

    public function test_paste_parser_only_invents_an_airport_pickup_for_a_real_airport(): void
    {
        $parser = app(\App\Services\Intake\FreeIntakeParser::class);

        $home = $parser->parse("Sam - 07868 882217\n23rd November 2026\nArriving at Sprotbrough 10:30\nGoing to East Midlands Airport");
        $this->assertStringNotContainsString('Sprotbrough Airport', (string) ($home['pickup_address'] ?? ''));
        $this->assertFalse(\App\Support\AirportMatcher::isAirport($home['pickup_address'] ?? null));

        $man = $parser->parse("Lawrence - 07868 882217\n23rd November 2026\nLanding in Manchester 15:05\nHome address: 19 Horsewood Road S13 9WL");
        $this->assertStringContainsString('Manchester Airport', (string) $man['pickup_address']);
    }
}
