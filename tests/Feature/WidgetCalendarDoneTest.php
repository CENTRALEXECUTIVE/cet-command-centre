<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 27 Nov 2026: the calendar closed on the day tap and had nothing to press after the time. */
class WidgetCalendarDoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_calendar_has_a_done_button_and_does_not_close_on_a_day_tap(): void
    {
        $html = $this->get(route('widget.book'))->assertOk()->getContent();
        $this->assertStringContainsString("done.className = 'cet-cal-done'", $html);
        $this->assertStringContainsString("cal.addEventListener('click', function (e) { e.stopPropagation(); });", $html);
        $this->assertStringNotContainsString('render(); commit(); close();', $html);
    }
}
