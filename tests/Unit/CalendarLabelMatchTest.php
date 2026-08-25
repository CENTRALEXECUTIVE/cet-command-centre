<?php

namespace Tests\Unit;

use App\Models\CalendarEvent;
use PHPUnit\Framework\TestCase;

/**
 * Calendar field labels must match tolerantly — hyphens and spaces are
 * interchangeable — so "Drop off Location" (space) is picked up by
 * "Drop-off Location" (hyphen) and a field never wrongly reads as "Unknown".
 */
class CalendarLabelMatchTest extends TestCase
{
    private function event(string $desc): CalendarEvent
    {
        return new CalendarEvent(['description' => $desc]);
    }

    public function test_drop_off_with_a_space_is_matched_by_the_hyphen_label(): void
    {
        $e = $this->event("• Drop off Location: Navigation House, S2 5SU\n• Vehicle Type: Executive");

        $this->assertSame('Navigation House, S2 5SU', $e->descriptionValue('Drop-off Location'));
    }

    public function test_drop_off_with_a_hyphen_still_matches(): void
    {
        $e = $this->event("• Drop-off Location: 22 Broad Elms Lane\n• Vehicle Type: Saloon");

        $this->assertSame('22 Broad Elms Lane', $e->descriptionValue('Drop-off Location'));
    }

    public function test_a_normal_label_is_unaffected(): void
    {
        $e = $this->event("• Pickup Location: Manchester Airport (MAN)\n• Payment: Paid £95");

        $this->assertSame('Manchester Airport (MAN)', $e->descriptionValue('Pickup Location'));
        $this->assertSame('Paid £95', $e->descriptionValue('Payment'));
    }

    public function test_a_missing_field_is_still_null(): void
    {
        $e = $this->event("• Pickup Location: Sheffield");

        $this->assertNull($e->descriptionValue('Drop-off Location'));
    }
}
