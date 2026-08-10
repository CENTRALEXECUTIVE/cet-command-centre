<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The driver's money line must be identical every time the link is opened —
 * never flipping between "Paid" and the cash amount. Duplicate calendar events
 * (which caused the flip) are loaded through the REAL link route here to prove
 * the amount is stable across many reloads.
 */
class DriverLinkMoneyStabilityTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithTwoEvents(string $newestDescription, string $olderDescription): Booking
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'payment_method' => PaymentMethod::Card->value,
            'payment_status' => 'paid', // ETO stamps "paid" from "Deposit £10 Paid"
            'quoted_price' => 140, 'final_price' => 140,
        ]);
        foreach ([$olderDescription, $newestDescription] as $desc) {
            $booking->calendarEvents()->create([
                'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
                'description' => $desc,
                'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
            ]);
        }

        return $booking->fresh();
    }

    public function test_amount_is_stable_when_the_newest_event_has_the_full_line(): void
    {
        $booking = $this->bookingWithTwoEvents(
            newestDescription: "📑 Booking Confirmation\n• *Payment:* Deposit £10 Paid – £130 Cash Due",
            olderDescription: "📑 Booking Confirmation\n• *Payment:* Deposit £10 Paid",
        );
        $token = $booking->driverLinkToken();

        for ($i = 0; $i < 8; $i++) {
            $this->get(route('driver.link', $token))
                ->assertOk()
                ->assertSee('Collect the cash')
                ->assertSee('£130')
                ->assertDontSee('collect nothing');
        }
    }

    public function test_amount_is_stable_even_when_the_newest_event_is_truncated(): void
    {
        // The dangerous case: the newest event only says "Deposit £10 Paid".
        // Reading every source still finds the £130 cash-due line.
        $booking = $this->bookingWithTwoEvents(
            newestDescription: "📑 Booking Confirmation\n• *Payment:* Deposit £10 Paid",
            olderDescription: "📑 Booking Confirmation\n• *Payment:* Deposit £10 Paid – £130 Cash Due",
        );
        $token = $booking->driverLinkToken();

        for ($i = 0; $i < 8; $i++) {
            $this->get(route('driver.link', $token))
                ->assertOk()
                ->assertSee('£130')
                ->assertDontSee('collect nothing');
        }
    }

    public function test_opcache_reset_route_rejects_a_bad_token(): void
    {
        $this->get('/__cet/opcache/not-the-token')->assertNotFound();
    }

    public function test_opcache_reset_route_accepts_the_derived_token(): void
    {
        $token = hash_hmac('sha256', 'opcache-reset', (string) config('app.key'));

        $this->get('/__cet/opcache/'.$token)
            ->assertOk()
            ->assertJsonStructure(['opcache_reset', 'at']);
    }
}
