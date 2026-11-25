<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA 25 Nov 2026: the profit report counted a cash job's driver pay twice (cash + pay). */
class ProfitDriverCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cash_job_costs_the_driver_pay_not_cash_plus_pay(): void
    {
        $b = Booking::factory()->create(['payment_method' => 'cash', 'quoted_price' => 120, 'final_price' => 120,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds LS1 4DY',
            'meta' => ['payment' => 'Cash £120', 'payroll' => ['pay' => 108]]]);
        $this->assertSame(108.0, $b->fresh()->driverCost());
    }

    public function test_a_card_job_costs_the_driver_pay(): void
    {
        $b = Booking::factory()->create(['payment_method' => 'card', 'quoted_price' => 120, 'final_price' => 120,
            'meta' => ['payroll' => ['pay' => 108]]]);
        $this->assertSame(108.0, $b->fresh()->driverCost());
    }
}
