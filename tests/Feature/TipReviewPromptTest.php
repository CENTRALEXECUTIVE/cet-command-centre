<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer who's just tipped is a happy one — the tip thank-you page invites
 * a Google review with the review link.
 */
class TipReviewPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tip_thanks_page_shows_the_review_link(): void
    {
        config(['cet.review_url' => 'https://g.page/r/review-test/review']);
        $booking = Booking::factory()->create();
        $token = $booking->tipToken();

        $this->get(route('tip.thanks', $token))
            ->assertOk()
            ->assertSee('Leave a review')
            ->assertSee('https://g.page/r/review-test/review');
    }

    public function test_a_cash_tip_also_prompts_for_a_review(): void
    {
        config(['cet.review_url' => 'https://g.page/r/review-test/review']);
        $booking = Booking::factory()->create();
        $token = $booking->tipToken();

        $this->get(route('tip.thanks', ['token' => $token, 'cash' => 1]))
            ->assertOk()
            ->assertSee('Leave a review');
    }

    public function test_no_review_section_when_no_review_url_is_set(): void
    {
        config(['cet.review_url' => '']);
        $booking = Booking::factory()->create();

        $this->get(route('tip.thanks', $booking->tipToken()))
            ->assertOk()
            ->assertDontSee('Leave a review');
    }

    public function test_no_review_on_the_outbound_while_the_return_is_still_to_run(): void
    {
        config(['cet.review_url' => 'https://g.page/r/review-test/review']);
        // Outbound complete, return still pending — a tip now must NOT ask for a review.
        $return = Booking::factory()->create(['status' => \App\Enums\BookingStatus::Accepted->value]);
        $outbound = Booking::factory()->create([
            'status' => \App\Enums\BookingStatus::Complete->value,
            'linked_booking_id' => $return->id,
        ]);

        $this->get(route('tip.thanks', $outbound->tipToken()))
            ->assertOk()
            ->assertDontSee('Leave a review');
    }

    public function test_review_is_offered_once_the_whole_trip_is_complete(): void
    {
        config(['cet.review_url' => 'https://g.page/r/review-test/review']);
        $return = Booking::factory()->create(['status' => \App\Enums\BookingStatus::Complete->value]);
        $outbound = Booking::factory()->create([
            'status' => \App\Enums\BookingStatus::Complete->value,
            'linked_booking_id' => $return->id,
        ]);

        // Tipping on the return (both legs done) offers the review.
        $this->get(route('tip.thanks', $return->tipToken()))
            ->assertOk()
            ->assertSee('Leave a review');
    }

    public function test_a_customer_already_asked_is_not_prompted_again(): void
    {
        config(['cet.review_url' => 'https://g.page/r/review-test/review']);
        $booking = Booking::factory()->create();
        // They were already sent/queued a review request on this booking.
        $booking->messages()->create([
            'channel' => 'whatsapp', 'direction' => 'out', 'type' => 'review_request',
            'to_address' => '+447700900000', 'body' => 'How was your journey?', 'status' => 'queued',
        ]);

        $this->get(route('tip.thanks', $booking->tipToken()))
            ->assertOk()
            ->assertDontSee('Leave a review');
    }
}
