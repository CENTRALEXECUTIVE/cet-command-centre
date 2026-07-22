<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupeReviewRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function review(string $to, string $status, ?Booking $booking = null): Message
    {
        return Message::create([
            'booking_id' => $booking?->id,
            'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'review_request',
            'to_address' => $to, 'body' => 'review', 'status' => $status,
            'scheduled_for' => now(),
        ]);
    }

    public function test_it_keeps_one_queued_review_per_number(): void
    {
        // Same person (same number, different formats) with two queued reviews.
        $a = $this->review('07700900123', 'queued');
        $b = $this->review('+447700900123', 'queued');
        // A different customer — untouched.
        $other = $this->review('07700900999', 'queued');

        $this->artisan('cet:dedupe-reviews')->assertSuccessful();

        // Earliest of the pair survives; the other is gone; the unrelated one stays.
        $this->assertNotNull($a->fresh());
        $this->assertNull($b->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_it_drops_queued_reviews_when_one_was_already_sent(): void
    {
        $sent = $this->review('07700900123', 'sent');
        $queued = $this->review('447700900123', 'queued');

        $this->artisan('cet:dedupe-reviews')->assertSuccessful();

        // Already asked → the queued one goes; the sent record is kept.
        $this->assertNotNull($sent->fresh());
        $this->assertNull($queued->fresh());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->review('07700900123', 'queued');
        $this->review('+447700900123', 'queued');

        $this->artisan('cet:dedupe-reviews --dry-run')->assertSuccessful();

        $this->assertSame(2, Message::where('type', 'review_request')->count());
    }
}
