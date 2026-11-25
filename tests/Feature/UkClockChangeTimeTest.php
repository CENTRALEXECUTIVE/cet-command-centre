<?php

namespace Tests\Feature;

use App\Support\UkTime;
use Illuminate\Http\Request;
use Tests\TestCase;

/** A clocks-forward time is never refused: it's moved to 02:00 and the office is told. */
class UkClockChangeTimeTest extends TestCase
{
    private function fix(array $in, ?string $notes = 'notes'): Request
    {
        $r = Request::create('/', 'POST', $in);
        UkTime::fixGap($r, ['pickup_at', 'return_pickup_at'], $notes);

        return $r;
    }

    public function test_gap_time_is_moved_to_two_am_with_an_office_note(): void
    {
        $r = $this->fix(['pickup_at' => '2027-03-28T01:30', 'notes' => 'Two cases']);
        $this->assertSame('2027-03-28T02:00', $r->input('pickup_at'));
        $this->assertStringStartsWith('Two cases', $r->input('notes'));
        $this->assertStringContainsString('please confirm the time with the customer', $r->input('notes'));
    }

    public function test_normal_and_clocks_back_times_are_untouched(): void
    {
        foreach (['2027-03-28T02:00', '2027-10-31T01:30', '2026-12-23T01:30'] as $t) {
            $r = $this->fix(['pickup_at' => $t]);
            $this->assertSame($t, $r->input('pickup_at'));
            $this->assertNull($r->input('notes'));
        }
    }

    public function test_office_form_gets_no_note(): void
    {
        $r = $this->fix(['pickup_at' => '2027-03-28 01:15', 'return_pickup_at' => '2027-03-28T01:45'], null);
        $this->assertSame('2027-03-28 02:00', $r->input('pickup_at'));
        $this->assertSame('2027-03-28T02:00', $r->input('return_pickup_at'));
        $this->assertNull($r->input('notes'));
    }
}
