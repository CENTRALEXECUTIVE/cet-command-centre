<?php

namespace Tests\Unit;

use App\Support\AirportMatcher;
use PHPUnit\Framework\TestCase;

class AirportMatcherTest extends TestCase
{
    public function test_bracketed_code(): void
    {
        $this->assertSame('MAN', AirportMatcher::codeFor('Manchester Airport (MAN), Terminal 2'));
    }

    public function test_name_alias(): void
    {
        $this->assertSame('LHR', AirportMatcher::codeFor('Heathrow Terminal 5'));
        $this->assertSame('MAN', AirportMatcher::codeFor('manchester airport m90 1qx'));
    }

    public function test_terminal_plus_city_is_recognised(): void
    {
        // The case that used to fall through to FREE ROAM.
        $this->assertSame('MAN', AirportMatcher::codeFor('Terminal 2, Manchester'));
        $this->assertSame('MAN', AirportMatcher::codeFor('T2 Manchester'));
        $this->assertSame('BHX', AirportMatcher::codeFor('Terminal 1, Birmingham'));
    }

    public function test_a_plain_city_address_is_not_an_airport(): void
    {
        // No terminal/airport token → must NOT be read as the airport.
        $this->assertNull(AirportMatcher::codeFor('12 Deansgate, Manchester'));
        $this->assertNull(AirportMatcher::codeFor('2 Worrygoose Lane, Rotherham'));
    }

    public function test_london_terminal_is_left_ambiguous(): void
    {
        // "Terminal, London" could be any of four — don't guess.
        $this->assertNull(AirportMatcher::codeFor('Terminal 3, London'));
    }

    public function test_london_airports_are_recognised_by_locality_with_a_terminal(): void
    {
        // The real ETO Heathrow drop-off: a terminal + the LOCALITY, no "(LHR)"
        // and no "Heathrow" — this used to fall through to FREE ROAM.
        $this->assertSame('LHR', AirportMatcher::codeFor('Terminal 5, Wallis Road, Longford, Hounslow, UK'));
        $this->assertSame('LHR', AirportMatcher::codeFor('Heathrow (LHR), Terminal 5, Longford, Hounslow'));
        $this->assertSame('LGW', AirportMatcher::codeFor('North Terminal, Crawley'));
    }

    public function test_a_plain_london_locality_without_a_terminal_is_not_an_airport(): void
    {
        // A residential Hounslow address (no terminal token) must NOT read as LHR.
        $this->assertNull(AirportMatcher::codeFor('14 Grove Road, Hounslow'));
    }

    public function test_is_airport(): void
    {
        $this->assertTrue(AirportMatcher::isAirport('Terminal 2, Manchester'));
        $this->assertTrue(AirportMatcher::isAirport('Manchester Airport'));
        $this->assertFalse(AirportMatcher::isAirport('12 Deansgate, Manchester'));
    }
}
