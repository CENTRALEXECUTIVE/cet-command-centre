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
 * ETO uses the …a / …b suffix for TWO things: a genuine outbound/return (reversed
 * route), AND two bookings on the SAME journey (e.g. two passengers on one flight
 * to the same place). Only the first is a return pair. The second must never be
 * paired or labelled "Return" — that was showing a real second booking as a
 * duplicate mistake.
 */
class EtoPairingGuardTest extends TestCase
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
            'is_booking' => true, 'cancelled' => false,
            'customer_name' => 'A Customer', 'customer_phone' => '07700900100',
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'destination_address' => '2 Worrygoose Lane, Rotherham S60 4AD',
            'pickup_at' => now()->addDays(3)->setTime(9, 0)->format('Y-m-d H:i'),
            'passengers' => 1, 'vehicle_type' => 'Executive', 'payment_status' => 'paid',
        ], $overrides);
    }

    public function test_two_bookings_on_the_same_journey_are_not_a_return_pair(): void
    {
        $svc = app(OutlookBookingService::class);

        // 9Y5MDRa — Janine, MAN → Worrygoose (an arrival).
        $svc->upsertFromParsed($this->parsed([
            'reference' => '9Y5MDRa', 'customer_name' => 'Janine Neill', 'customer_phone' => '07985454218',
        ]));
        // 9Y5MDRb — Sean, ALSO MAN → Worrygoose (same journey, different passenger).
        $b = $svc->upsertFromParsed($this->parsed([
            'reference' => '9Y5MDRb', 'customer_name' => 'Sean Neill', 'customer_phone' => '07700900101',
        ]))['booking'];

        $b = $b->fresh();
        $this->assertFalse((bool) $b->is_return_leg, 'same-journey leg is NOT a return');
        $this->assertNull($b->linked_booking_id, 'the two are not paired');
        $this->assertStringNotContainsString('Return', (string) $b->calendarEvent?->title);
    }

    public function test_a_genuine_reversed_route_is_still_a_return_pair(): void
    {
        $svc = app(OutlookBookingService::class);

        // Outbound: MAN → home.
        $svc->upsertFromParsed($this->parsed([
            'reference' => 'RTN7a',
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'destination_address' => '2 Worrygoose Lane, Rotherham S60 4AD',
        ]));
        // Return: home → MAN (reversed, different pickup).
        $b = $svc->upsertFromParsed($this->parsed([
            'reference' => 'RTN7b',
            'pickup_address' => '2 Worrygoose Lane, Rotherham S60 4AD',
            'destination_address' => 'Manchester Airport (MAN), Terminal 2',
        ]))['booking'];

        $b = $b->fresh();
        $this->assertTrue((bool) $b->is_return_leg, 'reversed route IS a return');
        $this->assertNotNull($b->linked_booking_id, 'the pair is linked');
        $this->assertStringContainsString('Return', (string) $b->calendarEvent?->title);
    }
}
