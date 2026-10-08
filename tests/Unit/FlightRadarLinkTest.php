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
            // The real-world break: the airline name in brackets + leading zero.
            'airline name in brackets' => ['QR027 (Qatar Airways)', 'qr27'],
            'icao with airline name' => ['QTR027 (Qatar Airways)', 'qr27'],
            'name before the code' => ['British Airways BA1234', 'ba1234'],
            // easyJet: FR24 indexes under the IATA code "u2…", NOT the ICAO
            // callsign "ezy…" (which returns "No flights found"). ETO stores the
            // ICAO form, so "EZY2366" must map BACK to "u22366".
            'easyjet iata kept' => ['U22366', 'u22366'],
            'easyjet with trailing text' => ['U22366 arrives 19:10', 'u22366'],
            'easyjet icao maps to iata' => ['EZY2366', 'u22366'],
            'easyjet real example EZY542' => ['EZY542', 'u2542'],
            // Wizz Air: IATA "W6" also kept as-is for FR24.
            'wizz iata kept' => ['W6 1234', 'w61234'],
            'wizz icao maps to iata' => ['WZZ1234', 'w61234'],
        ];
    }

    public function test_it_builds_a_flightradar_url(): void
    {
        $this->assertSame('https://www.flightradar24.com/data/flights/vs74', Booking::flightRadarLink('VS0074'));
        // The airline name and leading zero don't break the link any more.
        $this->assertSame('https://www.flightradar24.com/data/flights/qr27', Booking::flightRadarLink('QR027 (Qatar Airways)'));
        $this->assertNull(Booking::flightRadarLink(''));
        $this->assertNull(Booking::flightRadarLink(null));
    }

    public function test_the_google_status_link_uses_a_clean_code(): void
    {
        $url = Booking::flightSearchLink('QR027 (Qatar Airways)');

        // Clean "QR27", not "QR027(QATARAIRWAYS)".
        $this->assertStringContainsString(rawurlencode('flight QR27 status'), (string) $url);
    }
}
