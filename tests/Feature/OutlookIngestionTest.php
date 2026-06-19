<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Services\Ai\AnthropicService;
use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OutlookIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    private function parsed(array $overrides = []): array
    {
        return array_merge([
            'is_booking' => true, 'cancelled' => false, 'reference' => 'ZWR6MM',
            'customer_name' => 'James Watson', 'customer_email' => 'james@example.com',
            'customer_phone' => '07700900123', 'pickup_address' => 'Manchester Airport (MAN), Terminal 3',
            'destination_address' => 'Radisson Blu Hotel, Sheffield', 'pickup_at' => '2026-07-01 14:00',
            'passengers' => 2, 'vehicle_type' => 'Executive', 'flight_number' => 'BA123',
            'payment_status' => 'paid', 'payment_method' => 'Square', 'payment_text' => '£300 (Square) - Paid',
        ], $overrides);
    }

    public function test_unconfigured_graph_is_a_no_op(): void
    {
        $stats = app(OutlookBookingService::class)->ingest();
        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, Booking::count());
    }

    public function test_first_email_creates_booking_and_calendar_event_in_uk_time(): void
    {
        $result = app(OutlookBookingService::class)->upsertFromParsed($this->parsed());

        $this->assertEquals('created', $result['action']);
        $booking = Booking::where('external_reference', 'ZWR6MM')->first();
        $this->assertNotNull($booking);
        $this->assertEquals('eto', $booking->source_system);
        $this->assertEquals('MAN', $booking->airport->code);

        $event = CalendarEvent::where('booking_id', $booking->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Europe/London', $event->timezone);
        // 14:00 UK pickup → 15:00 UK end (+1h), in the format *👀 James Watson MAN (ABDI/EXECUTIVE)*
        $this->assertEquals('14:00', $event->start_at->format('H:i'));
        $this->assertEquals('15:00', $event->end_at->format('H:i'));
        $this->assertStringContainsString('James Watson', $event->title);
        $this->assertStringContainsString('MAN', $event->title);
    }

    public function test_same_reference_updates_not_duplicates(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        // Same reference, changed time + destination.
        $result = $svc->upsertFromParsed($this->parsed([
            'pickup_at' => '2026-07-01 16:30',
            'destination_address' => 'The Leopold Hotel, Sheffield',
        ]));

        $this->assertEquals('updated', $result['action']);
        $this->assertEquals(1, Booking::where('external_reference', 'ZWR6MM')->count());

        $booking = Booking::where('external_reference', 'ZWR6MM')->first();
        $this->assertStringContainsString('Leopold', $booking->destination_address);
        $this->assertEquals('16:30', $booking->pickup_at->format('H:i'));
        // Calendar event moved too (still one event for the booking).
        $this->assertEquals(1, CalendarEvent::where('booking_id', $booking->id)->count());
        $this->assertEquals('16:30', $booking->calendarEvent->start_at->format('H:i'));
    }

    public function test_cancellation_email_cancels_the_booking(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        $result = $svc->upsertFromParsed(['is_booking' => true, 'cancelled' => true, 'reference' => 'ZWR6MM']);

        $this->assertEquals('cancelled', $result['action']);
        $this->assertEquals(BookingStatus::Cancelled, Booking::where('external_reference', 'ZWR6MM')->first()->status);
    }

    public function test_full_pipeline_creates_via_mocked_email_and_ai(): void
    {
        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('fetchUnread')->andReturn([[
            'id' => 'm1', 'subject' => 'New booking ZWR6MM', 'from' => 'eto@easytaxioffice.co.uk',
            'body' => 'Booking reference ZWR6MM, James Watson, Manchester Airport to Sheffield 01/07/2026 14:00',
        ]]);
        $mail->shouldReceive('markRead')->with('m1')->once();
        $this->app->instance(GraphMailClient::class, $mail);

        $ai = Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('completeJson')->andReturn($this->parsed());
        $this->app->instance(AnthropicService::class, $ai);

        $stats = app(OutlookBookingService::class)->ingest();

        $this->assertEquals(1, $stats['created']);
        $this->assertEquals(1, Booking::where('external_reference', 'ZWR6MM')->count());
    }

    public function test_real_eto_email_parses_free_without_ai(): void
    {
        // No Anthropic key configured — the deterministic ETO parser must handle
        // a real booking email on its own (zero cost).
        config(['services.anthropic.key' => null]);

        $body = <<<'EML'
        New booking CWJB1S has been created.

        Journey
        Date & time:	22/06/2026 11:45
        Time zone:	UTC+1 London
        Pickup:	Manchester Airport (MAN), Terminal 2, Manchester, UK
        Arrival flight number:	U22050
        Arrival time:	11:45
        Meet & Greet:	Required
        Dropoff:	North Lakes Hotel & Spa, Ullswater Road, Penrith, UK
        Vehicle type:	Executive
        Passengers:	1
        Hand luggage:	1
        Customer
        Name:	Jackie Donoghue
        Email:	JDonoghue@jeldwen.com
        Lead passenger
        Name:	Christian Michel
        Phone number:	+447741612887
        Email:	JDonoghue@jeldwen.com
        Reservation
        Reference number:	CWJB1S
        Booking date:	18/06/2026 11:00
        Summary:	Journey £300
        Total:	£310
        Payments:	£310 (Square) - Paid
        EML;

        $svc = app(OutlookBookingService::class);
        $parsed = $svc->parse('New booking CWJB1S has been created.', $body, 'noreply@easytaxioffice.co.uk');

        $this->assertNotNull($parsed);
        $this->assertEquals('CWJB1S', $parsed['reference']);
        $this->assertEquals('Christian Michel', $parsed['customer_name']); // lead passenger
        $this->assertEquals('+447741612887', $parsed['customer_phone']);
        $this->assertEquals('2026-06-22 11:45', $parsed['pickup_at']);
        $this->assertStringContainsString('Manchester Airport (MAN)', $parsed['pickup_address']);
        $this->assertStringContainsString('North Lakes Hotel', $parsed['destination_address']);
        $this->assertEquals('U22050', $parsed['flight_number']);

        $result = $svc->upsertFromParsed($parsed);
        $this->assertEquals('created', $result['action']);

        $booking = Booking::where('external_reference', 'CWJB1S')->first();
        $this->assertEquals('MAN', $booking->airport->code);
        $this->assertEquals('11:45', $booking->pickup_at->format('H:i'));

        // Same reference, amended time → updates, no duplicate.
        $amended = str_replace(['has been created', '11:45'], ['has been amended', '13:30'], $body);
        $result2 = $svc->upsertFromParsed($svc->parse('Booking CWJB1S has been amended.', $amended, null));
        $this->assertEquals('updated', $result2['action']);
        $this->assertEquals(1, Booking::where('external_reference', 'CWJB1S')->count());

        // Cancellation email cancels it.
        $cancel = $svc->parse('Booking CWJB1S has been cancelled.', 'Booking CWJB1S has been cancelled.', null);
        $this->assertTrue($cancel['cancelled']);
        $this->assertEquals('cancelled', $svc->upsertFromParsed($cancel)['action']);
    }

    public function test_eto_email_without_iata_code_or_lead_passenger(): void
    {
        config(['services.anthropic.key' => null]);

        $body = <<<'EML'
        New booking CH9BQQ has been created.

        Journey
        Date & time:	22/06/2026 22:15
        Time zone:	UTC+1 London
        Pickup:	Manchester Airport M90 1QX
        Arrival flight number:	RK2899
        Meet & Greet:	Required
        Dropoff:	Glebe Road, Sheffield S10 1FB, UK
        Vehicle type:	Executive
        Passengers:	2
        Hand luggage:	2
        Comments:	The flight arrives at Terminal 3
        Customer
        Name:	Steven Dickinson
        Phone number:	+447770602920
        Email:	dickys10@icloud.com
        Reservation
        Reference number:	CH9BQQ
        Booking date:	17/06/2026 11:34
        Total:	£110
        Payments:	£110 (Square) - Paid
        EML;

        $svc = app(OutlookBookingService::class);
        $parsed = $svc->parse('New booking CH9BQQ has been created.', $body, 'noreply@easytaxioffice.co.uk');

        $this->assertEquals('CH9BQQ', $parsed['reference']);
        $this->assertEquals('Steven Dickinson', $parsed['customer_name']); // no lead passenger → customer
        $this->assertEquals('+447770602920', $parsed['customer_phone']);
        $this->assertEquals('2026-06-22 22:15', $parsed['pickup_at']);
        $this->assertEquals('RK2899', $parsed['flight_number']);

        $booking = $svc->upsertFromParsed($parsed)['booking'];
        // Airport detected from the name + postcode despite no "(MAN)" code.
        $this->assertEquals('MAN', $booking->airport->code);
        $this->assertEquals('22:15', $booking->pickup_at->format('H:i'));
    }

    public function test_description_emoji_and_import_rules(): void
    {
        $svc = app(OutlookBookingService::class);

        // Fully paid card booking → no money emoji, formatted description.
        $event = $svc->upsertFromParsed($this->parsed([
            'meet_and_greet' => true, 'notes' => 'Flight at Terminal 3',
        ]))['booking']->calendarEvent;

        $this->assertStringNotContainsString('👀', $event->title);
        $this->assertStringNotContainsString('💰', $event->title);
        $this->assertStringContainsString('📑 Booking Confirmation', $event->description);
        $this->assertStringContainsString('*Booking Reference:* ZWR6MM', $event->description);
        $this->assertStringContainsString('*Meet & Greet:* Required', $event->description);
        $this->assertStringContainsString('Arrival (Meet & Greet)', $event->description);

        // Deposit (balance remaining) on card → 👀 + 3-day push.
        $deposit = $svc->upsertFromParsed($this->parsed([
            'reference' => 'DEP123', 'payment_status' => 'deposit', 'payment_method' => 'Stripe',
        ]))['booking']->calendarEvent;
        $this->assertStringContainsString('👀', $deposit->title);
        $this->assertContains(['method' => 'popup', 'minutes' => 4320], $deposit->notifications);

        // Child seat anywhere → 🚼.
        $child = $svc->upsertFromParsed($this->parsed([
            'reference' => 'KID999', 'child_seat' => true,
        ]))['booking']->calendarEvent;
        $this->assertStringContainsString('🚼', $child->title);

        // Pending ("Pay now") bookings ARE imported, marked 👀 (full balance out).
        $pending = $svc->upsertFromParsed($this->parsed([
            'reference' => 'PEND01', 'payment_status' => 'pending', 'payment_method' => 'Square',
        ]));
        $this->assertEquals('created', $pending['action']);
        $this->assertStringContainsString('👀', $pending['booking']->calendarEvent->title);

        // Then payment arrives for the same reference → updates, 👀 clears.
        $paid = $svc->upsertFromParsed($this->parsed([
            'reference' => 'PEND01', 'payment_status' => 'paid', 'payment_method' => 'Square',
        ]));
        $this->assertEquals('updated', $paid['action']);
        $this->assertEquals(1, Booking::where('external_reference', 'PEND01')->count());
        $this->assertStringNotContainsString('👀', $paid['booking']->calendarEvent->title);
    }

    public function test_via_stops_and_suitcases_are_captured(): void
    {
        config(['services.anthropic.key' => null]);

        $body = <<<'EML'
        New booking URTBYO has been created.
        Journey
        Date & time: 19/06/2026 15:55
        Pickup: East Midlands Airport (EMA), Castle Donington, Derby, UK
        Via: 54 Larch Avenue, Wickersley, Rotherham, UK
        28 Batworth Drive, Sheffield S5 8XX, UK
        Dropoff: 136 Sandford Grove Road, Nether Edge, Sheffield, UK
        Vehicle type: Executive 8 Seater
        Passengers: 4
        Suitcases: 4
        Reference number: URTBYO
        Total: £215
        Payments: £215 (Square) - Pending Pay now
        EML;

        $svc = app(OutlookBookingService::class);
        $parsed = $svc->parse('New booking URTBYO', $body, null);

        $this->assertEquals(['54 Larch Avenue, Wickersley, Rotherham, UK', '28 Batworth Drive, Sheffield S5 8XX, UK'], $parsed['stops']);

        $booking = $svc->upsertFromParsed($parsed)['booking'];
        $this->assertEquals(4, $booking->luggage); // from "Suitcases"
        $this->assertEquals('v-class', $booking->vehicleType->slug); // Executive 8 Seater → V Class
        $event = $booking->calendarEvent;
        $this->assertStringContainsString('*Stop 1:* 54 Larch Avenue', $event->description);
        $this->assertStringContainsString('*Stop 2:* 28 Batworth Drive', $event->description);
        // Order: Pickup → Stops → Drop-off (contiguous), drop-off straight after stops.
        $pickupPos = strpos($event->description, '*Pickup Location:*');
        $stop2Pos = strpos($event->description, '*Stop 2:*');
        $dropPos = strpos($event->description, '*Drop-off Location:*');
        $this->assertTrue($pickupPos < $stop2Pos && $stop2Pos < $dropPos, 'Pickup → Stops → Drop-off order');
        $this->assertStringContainsString('👀', $event->title); // pending → balance outstanding
    }

    public function test_html_eto_email_is_converted_and_parsed(): void
    {
        config(['services.anthropic.key' => null]);

        // A real ETO email is an HTML table — naive strip_tags() would collapse
        // it onto one line and the parser would skip it. bodyToText must keep
        // the label/value structure.
        $html = '<html><body><p>New booking URTBYO has been created.</p>'
            .'<table>'
            .'<tr><td>Date &amp; time:</td><td>22/06/2026 09:30</td></tr>'
            .'<tr><td>Pickup:</td><td>Leeds Bradford Airport (LBA), Leeds, UK</td></tr>'
            .'<tr><td>Dropoff:</td><td>Glebe Road, Sheffield S10 1FB, UK</td></tr>'
            .'<tr><td>Vehicle type:</td><td>Executive</td></tr>'
            .'<tr><td>Passengers:</td><td>2</td></tr>'
            .'<tr><td>Name:</td><td>Steven Dickinson</td></tr>'
            .'<tr><td>Phone number:</td><td>+447770602920</td></tr>'
            .'<tr><td>Reference number:</td><td>URTBYO</td></tr>'
            .'<tr><td>Total:</td><td>£120</td></tr>'
            .'<tr><td>Payments:</td><td>£120 (Square) - Paid</td></tr>'
            .'</table></body></html>';

        $text = app(\App\Services\Inbox\GraphMailClient::class)
            ->bodyToText(['contentType' => 'html', 'content' => $html]);

        $parsed = app(OutlookBookingService::class)->parse('New booking URTBYO has been created.', $text, null);

        $this->assertNotNull($parsed, 'HTML ETO email should parse');
        $this->assertEquals('URTBYO', $parsed['reference']);
        $this->assertEquals('2026-06-22 09:30', $parsed['pickup_at']);
        $this->assertEquals('paid', $parsed['payment_status']);

        $booking = app(OutlookBookingService::class)->upsertFromParsed($parsed)['booking'];
        $this->assertEquals('LBA', $booking->airport->code);
    }

    public function test_executive_jobs_get_rotation_driver_per_airport(): void
    {
        $this->seed(\Database\Seeders\RotationSeeder::class);
        $svc = app(OutlookBookingService::class);

        // MAN → ABDI; tag is the callsign (ABDI), not the full first name.
        $man = $svc->upsertFromParsed($this->parsed([
            'reference' => 'MAN001', 'pickup_address' => 'Manchester Airport (MAN)',
        ]))['booking'];
        $this->assertEquals('Abdirazak Hassan', $man->driver->name);
        $this->assertStringContainsString('(ABDI)', $man->calendarEvent->title);

        // LBA → MAJ.
        $lba = $svc->upsertFromParsed($this->parsed([
            'reference' => 'LBA001', 'pickup_address' => 'Leeds Bradford Airport (LBA)',
        ]))['booking'];
        $this->assertEquals('Majid Ali', $lba->driver->name);
        $this->assertStringContainsString('(MAJ)', $lba->calendarEvent->title);

        // Non-rotation vehicle (Executive 8 Seater → V Class) keeps no driver; tag = V CLASS.
        $vclass = $svc->upsertFromParsed($this->parsed([
            'reference' => 'VC001', 'vehicle_type' => 'Executive 8 Seater',
        ]))['booking'];
        $this->assertNull($vclass->driver);
        $this->assertStringContainsString('(V CLASS)', $vclass->calendarEvent->title);
    }

    public function test_non_booking_email_is_skipped(): void
    {
        $ai = Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('completeJson')->andReturn(['is_booking' => false]);
        $this->app->instance(AnthropicService::class, $ai);

        $this->assertNull(app(OutlookBookingService::class)->parse('Re: invoice', 'A question', 'x@y.com'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
