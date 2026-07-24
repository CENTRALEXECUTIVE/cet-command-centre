<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_completed_upcoming_and_cancelled_counts(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        Booking::factory()->create(['pickup_at' => '2026-07-05 09:00']);              // completed
        Booking::factory()->create(['pickup_at' => '2026-07-10 09:00']);              // completed
        Booking::factory()->create(['pickup_at' => '2026-07-28 09:00']);              // upcoming
        Booking::factory()->create(['pickup_at' => '2026-07-06 09:00', 'status' => BookingStatus::Cancelled]);

        $this->artisan('cet:month-check --month=2026-07')
            ->expectsOutputToContain('Completed (already ran):               2')
            ->expectsOutputToContain('Upcoming (still to come):              1')
            ->expectsOutputToContain('No duplicates found')
            ->assertSuccessful();

        Carbon::setTestNow();
    }

    public function test_it_flags_a_same_journey_duplicate(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $customer = Customer::create(['name' => 'Ngozi Nweze', 'phone' => '+447700900123']);

        // Same journey, two different references → a duplicate the count should flag.
        Booking::factory()->create(['customer_id' => $customer->id, 'pickup_at' => '2026-07-05 13:45', 'external_reference' => 'AAA1']);
        Booking::factory()->create(['customer_id' => $customer->id, 'pickup_at' => '2026-07-05 13:45', 'external_reference' => 'BBB2']);

        $this->artisan('cet:month-check --month=2026-07')
            ->expectsOutputToContain('Possible duplicates')
            ->assertSuccessful();

        Carbon::setTestNow();
    }
}
