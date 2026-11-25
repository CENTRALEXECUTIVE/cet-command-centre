<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Validator;

/** A UK time that doesn't exist (clocks go forward) is refused, not saved an hour later. */
class UkClockChangeTimeTest extends TestCase
{
    public function test_the_skipped_hour_is_refused_and_normal_times_pass(): void
    {
        $rule = ['t' => [\App\Support\UkTime::existsRule()]];
        $this->assertTrue(Validator::make(['t' => '2027-03-28T01:30'], $rule)->fails());
        $this->assertFalse(Validator::make(['t' => '2027-03-28T02:00'], $rule)->fails());
        $this->assertFalse(Validator::make(['t' => '2027-10-31T01:30'], $rule)->fails()); // exists (twice)
        $this->assertFalse(Validator::make(['t' => '2026-12-23T10:30'], $rule)->fails());
    }
}
