<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office can turn review requests OFF for a booking — e.g. a customer who had
 * a bad experience, or one we'd rather not ask. Nothing is auto-queued, the manual
 * request is refused, and any queued-but-unsent review is pulled from the list.
 */
class ReviewSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private function completedJob(): Booking
    {
        $customer = Customer::create(['name' => 'Grumpy Pax', 'phone' => '07700900123']);

        return Booking::factory()->create([
            'customer_id' => $customer->id,
            'status' => BookingStatus::Complete->value,
            'pickup_at' => now()->subMinutes(40),
        ]);
    }

    public function test_a_normal_completed_job_queues_a_review(): void
    {
        $booking = $this->completedJob();

        app(BookingNotifier::class)->scheduleReviewRequest($booking);

        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'review_request']);
    }

    public function test_suppressed_booking_never_queues_a_review_even_when_forced(): void
    {
        $booking = $this->completedJob();
        $booking->setReviewSuppressed(true);

        // Auto path AND the manual "Request a review" (force) both no-op.
        $this->assertNull(app(BookingNotifier::class)->scheduleReviewRequest($booking));
        $this->assertNull(app(BookingNotifier::class)->scheduleReviewRequest($booking, force: true));

        $this->assertDatabaseMissing('messages', ['booking_id' => $booking->id, 'type' => 'review_request']);
    }

    public function test_turning_reviews_off_removes_a_queued_review(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->completedJob();
        app(BookingNotifier::class)->scheduleReviewRequest($booking);
        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'review_request']);

        $this->actingAs($admin)
            ->post(route('bookings.review-sending', $booking), ['allow' => 0])
            ->assertRedirect();

        $this->assertTrue($booking->fresh()->reviewSuppressed());
        $this->assertDatabaseMissing('messages', ['booking_id' => $booking->id, 'type' => 'review_request']);
    }

    public function test_an_already_sent_review_is_left_alone_when_turning_off(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->completedJob();
        $booking->messages()->create([
            'channel' => 'whatsapp', 'direction' => 'outbound', 'to_address' => '07700900123',
            'type' => 'review_request', 'body' => 'How was your journey?', 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('bookings.review-sending', $booking), ['allow' => 0])->assertRedirect();

        // Sent history is preserved — we can't un-send it.
        $this->assertDatabaseHas('messages', [
            'booking_id' => $booking->id, 'type' => 'review_request', 'status' => 'sent',
        ]);
    }

    public function test_turning_reviews_back_on_restores_the_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->completedJob();
        $booking->setReviewSuppressed(true);

        $this->actingAs($admin)
            ->post(route('bookings.review-sending', $booking), ['allow' => 1])
            ->assertRedirect();

        $this->assertFalse($booking->fresh()->reviewSuppressed());
        $this->assertNotNull(app(BookingNotifier::class)->scheduleReviewRequest($booking->fresh()));
        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'review_request']);
    }

    public function test_the_toggle_is_admin_only(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->completedJob();

        $this->actingAs($driver)
            ->post(route('bookings.review-sending', $booking), ['allow' => 0])
            ->assertForbidden();

        $this->assertFalse($booking->fresh()->reviewSuppressed());
    }
}
