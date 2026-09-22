<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * cet:catchup-reviews rebuilds the review queue after an outage: every completed
 * job since the last review sent gets its request queued, with no 21-day cap.
 */
class CatchupReviewsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function completedJob(string $name, string $phone, Carbon $pickup): Booking
    {
        $customer = Customer::create(['name' => $name, 'phone' => $phone]);

        return Booking::factory()->create([
            'customer_id' => $customer->id,
            'status' => BookingStatus::Complete,
            'pickup_at' => $pickup,
        ]);
    }

    public function test_it_queues_reviews_for_every_completed_job_after_the_last_review(): void
    {
        // Evan already has a review request (the last one sent).
        $evan = $this->completedJob('Evan', '07700900001', now()->subDays(40));
        Message::create([
            'booking_id' => $evan->id,
            'type' => 'review_request',
            'channel' => 'whatsapp',
            'to_address' => '07700900001',
            'status' => 'sent',
            'body' => 'review',
        ]);

        // Two jobs completed AFTER Evan — even one older than the 21-day window
        // prepare-reminders would miss.
        $laterA = $this->completedJob('Aisha', '07700900002', now()->subDays(30));
        $laterB = $this->completedJob('Ben', '07700900003', now()->subDays(2));

        // One BEFORE Evan — must be left alone.
        $earlier = $this->completedJob('Old Job', '07700900009', now()->subDays(50));

        $this->artisan('cet:catchup-reviews')->assertSuccessful();

        $this->assertTrue(Message::where('type', 'review_request')->where('booking_id', $laterA->id)->exists());
        $this->assertTrue(Message::where('type', 'review_request')->where('booking_id', $laterB->id)->exists());
        $this->assertFalse(Message::where('type', 'review_request')->where('booking_id', $earlier->id)->exists());
        // Evan still has exactly one — never duplicated.
        $this->assertSame(1, Message::where('type', 'review_request')->where('booking_id', $evan->id)->count());
    }

    public function test_dry_run_lists_without_queueing(): void
    {
        $evan = $this->completedJob('Evan', '07700900001', now()->subDays(10));
        Message::create([
            'booking_id' => $evan->id, 'type' => 'review_request', 'channel' => 'whatsapp',
            'to_address' => '07700900001', 'status' => 'sent', 'body' => 'review',
        ]);
        $later = $this->completedJob('Aisha', '07700900002', now()->subDays(3));

        $this->artisan('cet:catchup-reviews --dry')->assertSuccessful();

        $this->assertFalse(Message::where('type', 'review_request')->where('booking_id', $later->id)->exists());
    }
}
