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
}
