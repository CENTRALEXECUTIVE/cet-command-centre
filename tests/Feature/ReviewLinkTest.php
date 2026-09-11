<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Google review link reaches the customer through the queued "Review request"
 * message on the booking page — sent by hand on WhatsApp, any time (nothing
 * auto-sends to customers). The old standalone "Review link" block was removed as
 * a duplicate of this.
 */
class ReviewLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_review_request_message_carries_the_review_link_with_a_send_button(): void
    {
        config(['cet.review_url' => 'https://g.page/r/TEST/review']);
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Jo Rider', 'phone' => '07700900123'])->id,
        ]);

        Message::create([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'review_request',
            'to_address' => '07700900123', 'status' => 'queued',
            'body' => app(\App\Services\Messaging\BookingNotifier::class)->reviewBody($booking),
        ]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Send on WhatsApp')
            ->assertSee('https://g.page/r/TEST/review');
    }

    public function test_the_standalone_review_link_block_is_gone(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Jo Rider', 'phone' => '07700900123'])->id,
        ]);

        // No queued review message and no duplicate "Send review link" block.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('Send review link');
    }
}
