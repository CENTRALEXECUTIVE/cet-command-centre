<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The driver/office must SEE the same flight code the Track-flight link resolves
 * to. ETO feeds the ICAO callsign ("EZY542") but Flightradar24 indexes under the
 * IATA code ("u2542") — showing EZY while the link needed U2 caused endless "No
 * flights found" confusion. flightDisplayCode() shows the clean IATA form.
 */
class FlightDisplayCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_easyjet_icao_is_shown_as_the_iata_code(): void
    {
        $booking = Booking::factory()->create(['flight_number' => 'EZY542']);

        $this->assertSame('U2542', $booking->flightDisplayCode());
        $this->assertSame('https://www.flightradar24.com/data/flights/u2542', $booking->flightRadarUrl());
    }

    public function test_plain_iata_is_cleaned_and_matches_the_link(): void
    {
        $booking = Booking::factory()->create(['flight_number' => 'BA0123']);

        $this->assertSame('BA123', $booking->flightDisplayCode());
        $this->assertSame('https://www.flightradar24.com/data/flights/ba123', $booking->flightRadarUrl());
    }

    public function test_unparseable_value_falls_back_to_the_raw_text(): void
    {
        $booking = Booking::factory()->create(['flight_number' => 'TBC']);

        $this->assertSame('TBC', $booking->flightDisplayCode());
    }

    public function test_no_flight_returns_null(): void
    {
        $booking = Booking::factory()->create(['flight_number' => null]);

        $this->assertNull($booking->flightDisplayCode());
    }
}
