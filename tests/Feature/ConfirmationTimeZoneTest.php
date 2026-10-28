<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The confirmation email's "Time zone" line must match the UK clock on the
 * pickup DATE — it was hard-coded "UTC+1" and told a November customer the
 * wrong offset (UK is on GMT/UTC+0 from late October to late March).
 */
class ConfirmationTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_winter_pickup_shows_gmt(): void
    {
        $b = Booking::factory()->create(['pickup_at' => Carbon::parse('2026-11-02 13:45', 'Europe/London')]);

        $html = (new BookingConfirmationMail($b))->render();

        $this->assertStringContainsString('UTC+0 London (GMT)', $html);
        $this->assertStringNotContainsString('UTC+1', $html);
    }

    public function test_a_summer_pickup_shows_bst(): void
    {
        $b = Booking::factory()->create(['pickup_at' => Carbon::parse('2026-07-02 13:45', 'Europe/London')]);

        $this->assertStringContainsString('UTC+1 London (BST)', (new BookingConfirmationMail($b))->render());
    }
}
