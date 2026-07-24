<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearSquareTipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_square_tips_but_keeps_manual_cash_tips(): void
    {
        $booking = Booking::factory()->create();

        // A fare mis-logged as a Square tip, and a genuine cash tip.
        $booking->logTip(650.0, 'card', source: 'square', squarePaymentId: 'pay_bad');
        $booking->logTip(10.0, 'cash'); // manual, no square payment id

        $this->assertSame(660.0, $booking->fresh()->tipsTotal());

        $this->artisan('cet:clear-square-tips')->assertSuccessful();

        // Only the manual cash tip survives.
        $this->assertSame(10.0, $booking->fresh()->tipsTotal());
    }

    public function test_dry_run_removes_nothing(): void
    {
        $booking = Booking::factory()->create();
        $booking->logTip(120.0, 'card', source: 'square', squarePaymentId: 'pay_x');

        $this->artisan('cet:clear-square-tips --dry-run')->assertSuccessful();

        $this->assertSame(120.0, $booking->fresh()->tipsTotal());
    }
}
