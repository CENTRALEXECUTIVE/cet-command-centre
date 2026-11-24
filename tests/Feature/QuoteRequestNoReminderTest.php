<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors (24 Nov 2026): an unconfirmed quote request must not prompt "send reminder". */
class QuoteRequestNoReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_confirmed_or_priced_jobs_are_reminder_worthy(): void
    {
        $quote = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => null, 'meta' => ['quote_request' => true]]);
        $quotedUnpaid = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => 195, 'payment_status' => 'pending', 'meta' => ['quote_request' => true]]);
        $quotedPaid = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => 195, 'payment_status' => 'paid', 'meta' => ['quote_request' => true]]);
        $normal = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => null, 'meta' => []]);
        $allocated = Booking::factory()->create(['status' => BookingStatus::Allocated, 'quoted_price' => null]);

        $ids = Booking::reminderWorthy()->pluck('id')->all();
        $this->assertNotContains($quote->id, $ids);
        $this->assertNotContains($quotedUnpaid->id, $ids);
        $this->assertContains($quotedPaid->id, $ids);
        $this->assertContains($normal->id, $ids);
        $this->assertContains($allocated->id, $ids);
    }
}
