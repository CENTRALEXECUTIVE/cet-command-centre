<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office can send the customer a Google review link straight from the booking
 * page, any time (manual WhatsApp send — nothing auto-sends to customers).
 */
class ReviewLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_booking_page_shows_a_send_review_link_button(): void
    {
        config(['cet.review_url' => 'https://g.page/r/TEST/review']);
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Jo Rider', 'phone' => '07700900123'])->id,
        ]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Send review link')
            ->assertSee('https://g.page/r/TEST/review');
    }
}
