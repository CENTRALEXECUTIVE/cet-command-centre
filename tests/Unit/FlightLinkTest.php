<?php

namespace Tests\Unit;

use App\Models\Booking;
use PHPUnit\Framework\TestCase;

class FlightLinkTest extends TestCase
{
    public function test_flightradar_link_normalises_the_flight_number(): void
    {
        $this->assertEquals(
            'https://www.flightradar24.com/data/flights/ba1234',
            Booking::flightRadarLink('BA 1234')
        );
        $this->assertNull(Booking::flightRadarLink(''));
        $this->assertNull(Booking::flightRadarLink(null));
    }

    public function test_google_status_link_is_built(): void
    {
        $this->assertEquals(
            'https://www.google.com/search?q=flight%20BA1234%20status',
            Booking::flightSearchLink('ba 1234')
        );
    }
}
