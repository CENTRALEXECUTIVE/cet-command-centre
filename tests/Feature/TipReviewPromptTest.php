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
}
