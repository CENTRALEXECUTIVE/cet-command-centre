<?php

namespace Tests\Feature;

use App\Services\BookingIntakeService;
use App\Services\Intake\FreeIntakeParser;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeIntakeParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private const CALENDAR_BLOCK = <<<'TXT'
📑 *Booking Confirmation – Arrival*
• *Date & Time:* 24/11/2026 – 07:30
• *Customer Name:* Emma Cusworth
• *Contact No:* +447501028381
• *Passengers:* 5
• *Luggage:* 8 Suitcases + 4 Hand Luggage
• *Pickup Location:* Manchester Airport (MAN), Terminal 2
• *Flight Number:* VS0074
• *Drop-off Location:* 5 Moorbridge Crescent, Brampton, Barnsley S73 0YA
• *Vehicle Type:* Minibus
• *Payment:* Paid £350 (Stripe)
• *Booking Reference:* DBJ6TRb
TXT;

    private const ETO_EMAIL = <<<'TXT'
New booking ABC123 has been created.
Journey
Date & time: 22/06/2026 11:45
Pickup: Manchester Airport (MAN), Terminal 2, Manchester, UK
Dropoff: North Lakes Hotel & Spa, Ullswater Road, Penrith, UK
Vehicle type: Executive
Passengers: 2
Suitcases: 3
Hand luggage: 2
Lead passenger
Name: Christian Michel
Phone number: +447741612887
Reservation
Reference number: ABC123
Total: £110
Payments: £110 (Square) - Paid
TXT;

    public function test_parses_the_cet_calendar_block_for_free(): void
    {
        $f = app(FreeIntakeParser::class)->parse(self::CALENDAR_BLOCK);

        $this->assertSame('Emma Cusworth', $f['lead_name']);
        $this->assertSame('+447501028381', $f['contact_no']);
        $this->assertSame('2026-11-24 07:30', $f['pickup_at']);
        $this->assertSame('Manchester Airport (MAN), Terminal 2', $f['pickup_address']);
        $this->assertSame('5 Moorbridge Crescent, Brampton, Barnsley S73 0YA', $f['destination_address']);
        $this->assertSame('MAN', $f['where']);
        $this->assertSame('VS0074', $f['flight_number']);
        $this->assertSame(5, $f['passengers']);
        $this->assertSame(8, $f['suitcases']);
        $this->assertSame(4, $f['hand_luggage']);
        $this->assertSame('Minibus', $f['vehicle']);
        $this->assertSame('card', $f['payment']);
        $this->assertTrue($f['paid']);
    }

    public function test_parses_an_eto_email_for_free(): void
    {
        $f = app(FreeIntakeParser::class)->parse(self::ETO_EMAIL);

        $this->assertSame('Christian Michel', $f['lead_name']);
        $this->assertSame('2026-06-22 11:45', $f['pickup_at']);
        $this->assertSame('MAN', $f['where']);
        $this->assertSame(3, $f['suitcases']);
        $this->assertSame(2, $f['hand_luggage']);
        $this->assertTrue($f['paid']);
    }

    public function test_intake_never_calls_the_paid_ai(): void
    {
        // If anything touches the Anthropic service the test fails — pasting a
        // booking must cost £0.
        $ai = \Mockery::mock(\App\Services\Ai\AnthropicService::class);
        $ai->shouldNotReceive('completeJson');
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('configured')->andReturnTrue(); // even when a key exists
        $this->instance(\App\Services\Ai\AnthropicService::class, $ai);

        $fields = app(BookingIntakeService::class)->parse(self::CALENDAR_BLOCK);

        $this->assertSame('Emma Cusworth', $fields['lead_name']);
        $this->assertSame('2026-11-24 07:30', $fields['pickup_at']);
    }

    public function test_pasting_into_the_intake_page_builds_the_calendar_preview(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('intake.preview'), ['raw' => self::CALENDAR_BLOCK])
            ->assertOk()
            ->assertSee('Copy onto the calendar')
            ->assertSee('Emma Cusworth');

        // The generated calendar title carries the CET format: name + WHERE + tag.
        $response->assertSee('MAN');
    }

    public function test_conversational_covering_job_is_parsed(): void
    {
        $f = app(FreeIntakeParser::class)->parse(<<<'TXT'
Lawrence - 07868 882217
2 Customers 2 Cases
23rd September 2026
Landing in Manchester 15:05
Flight number: LS1754
Home address: 19 Horsewood Road S13 9WL
estate job
Covering job for another driver. reference is Ryanhn
TXT);

        $this->assertSame('Lawrence', $f['lead_name']);
        $this->assertSame('07868882217', $f['contact_no']);
        $this->assertSame(2, $f['passengers']);
        $this->assertSame(2, $f['suitcases']);
        $this->assertSame('2026-09-23 15:05', $f['pickup_at']);
        $this->assertSame('LS1754', $f['flight_number']);
        $this->assertStringContainsString('Manchester Airport', $f['pickup_address']);
        $this->assertStringContainsString('19 Horsewood Road', $f['destination_address']);
        $this->assertSame('MAN', $f['where']);
        $this->assertSame('Estate', $f['vehicle']);
        $this->assertSame('Ryanhn', $f['reference']);
    }

    public function test_loose_text_still_extracts_the_essentials(): void
    {
        $f = app(FreeIntakeParser::class)->parse(
            'Hi can you book John Barnes from Sheffield S10 2QW to Heathrow Terminal 5 '
            .'on 15/08/2026 at 04:30, 2 passengers, cash on the day. 07700 900 123'
        );

        $this->assertSame('2026-08-15 04:30', $f['pickup_at']);
        $this->assertSame('LHR', $f['where']);
        $this->assertSame('cash', $f['payment']);
        $this->assertSame('07700900123', $f['contact_no']);
    }

    public function test_it_parses_the_office_transfer_block(): void
    {
        $block = <<<'TXT'
Job Type: Transfer
Date & Time: 10:35 – Fri, 09 Oct 2026
Passenger: Naomi Beckett
Pickup: 15 Manor Fields, Goole, DN14 8TL
Drop-off: Bicycle Parking Area, York, YO23 1EX
Vehicle: Executive
Price: £140 – PAID
Driver Notes: York Racecourse
TXT;
        $f = app(FreeIntakeParser::class)->parse($block);

        $this->assertSame('Naomi Beckett', $f['lead_name']);
        $this->assertSame('2026-10-09 10:35', $f['pickup_at']);
        $this->assertStringContainsString('Goole', $f['pickup_address']);
        $this->assertStringContainsString('York', $f['destination_address']);
        $this->assertSame(140.0, $f['price']);
        $this->assertTrue($f['paid']);
        $this->assertStringContainsString('York Racecourse', $f['notes']);
        // The date must NOT be mis-read: no "OCT2026" flight, no "2026" passengers.
        $this->assertSame('', $f['flight_number']);
        $this->assertSame(1, $f['passengers']);
    }

    public function test_a_v_class_transfer_block_resolves_to_v_class(): void
    {
        $block = <<<'TXT'
Date & Time: 09:50 – Fri, 09 Oct 2026
Passenger: Mark Haran
Pickup: 4 Spring Gardens, Barnsley, S74 9QW
Drop-off: Bicycle Parking Area, York, YO23 1EX
Vehicle: V-Class
Price: £170 – PAID
Driver Notes: York Racecourse
TXT;
        $f = app(FreeIntakeParser::class)->parse($block);
        $this->assertSame('Mark Haran', $f['lead_name']);
        $this->assertSame('2026-10-09 09:50', $f['pickup_at']);
        $this->assertSame(170.0, $f['price']);

        // The vehicle label resolves to the V Class type when the booking is created.
        $booking = app(BookingIntakeService::class)->create($f, null);
        $this->assertSame('v-class', $booking->vehicleType->slug);
    }
}
