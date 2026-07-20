<?php

namespace Tests\Unit;

use App\Models\Booking;
use PHPUnit\Framework\TestCase;

class FlightRadarLinkTest extends TestCase
{
    /**
     * @dataProvider flights
     */
    public function test_it_normalises_flight_numbers_for_flightradar(string $input, string $expected): void
    {
        $this->assertSame($expected, Booking::normaliseFlightNumberForFr24($input));
    }

    public static function flights(): array
    {
        return [
            'iata plain' => ['DL9332', 'dl9332'],
            'iata with spaces' => ['BA 123', 'ba123'],
            'leading zeros stripped' => ['VS0074', 'vs74'],
            'icao to iata (Air France)' => ['AFR1169', 'af1169'],
            'icao to iata (British Airways)' => ['BAW2490', 'ba2490'],
            'icao to iata (Virgin, leading zero)' => ['VIR0075', 'vs75'],
            'lowercase input' => ['ls919', 'ls919'],
            'trailing letter kept' => ['BA123A', 'ba123a'],
            'unknown format falls back clean' => ['XYZ', 'xyz'],
        ];
    }

    public function test_it_builds_a_flightradar_url(): void
    {
        $this->assertSame('https://www.flightradar24.com/data/flights/vs74', Booking::flightRadarLink('VS0074'));
        $this->assertNull(Booking::flightRadarLink(''));
        $this->assertNull(Booking::flightRadarLink(null));
    }
}
