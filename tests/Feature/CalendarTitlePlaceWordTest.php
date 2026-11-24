<?php

namespace Tests\Feature;

use App\Services\CalendarEventBuilder;
use Tests\TestCase;

/** CET-04008C (24 Nov 2026): the calendar title read "Awais 80" (the house number). */
class CalendarTitlePlaceWordTest extends TestCase
{
    public function test_the_title_uses_the_town_not_the_house_number(): void
    {
        $this->assertSame('SALISBURY', CalendarEventBuilder::placeWord('80 Pinewood Way, Salisbury, UK, SP2 9HU'));
        $this->assertSame('SWINDON', CalendarEventBuilder::placeWord('141 Victoria Road, Swindon SN1 3BU, UK'));
        $this->assertSame('SWINDON', CalendarEventBuilder::placeWord('Swindon, UK'));
        $this->assertSame('LEEDS', CalendarEventBuilder::placeWord('Leeds'));
    }
}
