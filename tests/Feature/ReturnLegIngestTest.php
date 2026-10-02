<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A/B reference handling for a real round trip booked "fly in first, fly out later"
 * (ref …a = the arrival on one date, ref …b = the departure on another). Each leg
 * MUST keep its own journey — different direction, flight, date and price — and the
 * two must never collapse into two copies of the same leg. Guards the exact live
 * mix-up seen on booking 9Y5MDRa / 9Y5MDRb.
 */
class ReturnLegIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    private function leg(array $o): array
    {
        return array_merge([
            'is_booking' => true, 'cancelled' => false,
            'customer_name' => 'Janine Neill', 'booker_name' => 'Sean Neill',
            'customer_email' => 'janineneill71@gmail.com', 'customer_phone' => '07985454218',
            'passengers' => 1, 'vehicle_type' => 'Executive',
            'payment_status' => 'paid', 'payment_method' => 'Square',
            'suitcases' => 0, 'hand_luggage' => 1,
        ], $o);
    }

    public function test_fly_in_then_out_keeps_each_leg_correct_and_distinct(): void
    {
        $svc = app(OutlookBookingService::class);

        // …a = ARRIVAL (fly in) on 4 Oct, airport → home, £115, meet & greet.
        $svc->upsertFromParsed($this->leg([
            'reference' => '9Y5MDRa',
            'pickup_address' => 'Terminal 2, Manchester',
            'destination_address' => '2 Worrygoose Lane, Whiston, Rotherham S60 4AD',
            'pickup_at' => '2026-10-04 09:15',
            'flight_number' => 'LM0693', 'meet_and_greet' => true,
            'payment_text' => 'Paid £115 (Square)', 'total_amount' => 115.0,
        ]));

        // …b = DEPARTURE (fly out) on 8 Oct, home → airport, £105, no meet & greet.
        $svc->upsertFromParsed($this->leg([
            'reference' => '9Y5MDRb',
            'pickup_address' => '2 Worrygoose Lane, Whiston, Rotherham S60 4AD',
            'destination_address' => 'Terminal 2, Manchester',
            'pickup_at' => '2026-10-08 09:00',
            'flight_number' => 'LM0694', 'meet_and_greet' => false,
            'payment_text' => 'Paid £105 (Square)', 'total_amount' => 105.0,
        ]));

        $a = Booking::where('external_reference', '9Y5MDRa')->firstOrFail();
        $b = Booking::where('external_reference', '9Y5MDRb')->firstOrFail();

        // Two genuinely different bookings.
        $this->assertNotEquals($a->id, $b->id);

        // Leg A — the arrival, as booked.
        $this->assertSame('2026-10-04 09:15', $a->pickup_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('Terminal 2', $a->pickup_address);
        $this->assertStringContainsString('Worrygoose', $a->destination_address);
        $this->assertSame('LM0693', $a->flight_number);
        $this->assertEqualsWithDelta(115.0, (float) $a->fareAmount(), 0.01);
        $this->assertSame('Arrival', $a->meta['journey_label']);
        $this->assertTrue((bool) ($a->meta['meet_and_greet'] ?? false));
        $this->assertSame('MAN', $a->meta['where']);

        // Leg B — the departure, NOT a second copy of the arrival.
        $this->assertSame('2026-10-08 09:00', $b->pickup_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('Worrygoose', $b->pickup_address);
        $this->assertStringContainsString('Terminal 2', $b->destination_address);
        $this->assertSame('LM0694', $b->flight_number);
        $this->assertEqualsWithDelta(105.0, (float) $b->fareAmount(), 0.01);
        $this->assertSame('Departure', $b->meta['journey_label']);
        $this->assertSame('MAN', $b->meta['where']);

        // Paired: same driver, b flagged as the later/return leg, a is not.
        $this->assertSame($a->driver_id, $b->driver_id);
        $this->assertTrue((bool) $b->is_return_leg);
        $this->assertFalse((bool) $a->is_return_leg);
    }

    public function test_reingesting_each_leg_does_not_corrupt_the_other(): void
    {
        $svc = app(OutlookBookingService::class);
        $a = ['reference' => '9Y5MDRa', 'pickup_address' => 'Terminal 2, Manchester',
            'destination_address' => '2 Worrygoose Lane, Rotherham', 'pickup_at' => '2026-10-04 09:15',
            'flight_number' => 'LM0693', 'meet_and_greet' => true, 'total_amount' => 115.0, 'payment_text' => 'Paid £115 (Square)'];
        $b = ['reference' => '9Y5MDRb', 'pickup_address' => '2 Worrygoose Lane, Rotherham',
            'destination_address' => 'Terminal 2, Manchester', 'pickup_at' => '2026-10-08 09:00',
            'flight_number' => 'LM0694', 'total_amount' => 105.0, 'payment_text' => 'Paid £105 (Square)'];

        $svc->upsertFromParsed($this->leg($a));
        $svc->upsertFromParsed($this->leg($b));
        // Re-ingest both (the 5-minute cycle) — must stay two correct legs.
        $svc->upsertFromParsed($this->leg($a));
        $svc->upsertFromParsed($this->leg($b));

        $this->assertSame(2, Booking::whereIn('external_reference', ['9Y5MDRa', '9Y5MDRb'])->count());
        $this->assertSame('LM0693', Booking::where('external_reference', '9Y5MDRa')->value('flight_number'));
        $this->assertSame('LM0694', Booking::where('external_reference', '9Y5MDRb')->value('flight_number'));
    }
}
