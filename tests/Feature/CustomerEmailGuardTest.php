<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The shared safety gate: we never auto-email a customer about an old, finished,
 * cancelled or address-less booking — whatever path tries to send.
 */
class CustomerEmailGuardTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = []): Booking
    {
        $customer = Customer::factory()->create(['email' => 'c@example.com']);

        return Booking::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => BookingStatus::Pending->value,
        ], $overrides));
    }

    public function test_a_normal_upcoming_booking_is_emailable(): void
    {
        $this->assertTrue($this->booking()->customerEmailAllowed());
    }

    public function test_a_past_pickup_is_not_emailable(): void
    {
        $this->assertFalse($this->booking(['pickup_at' => now()->subDays(3)])->customerEmailAllowed());
    }

    public function test_a_terminal_booking_is_not_emailable(): void
    {
        $this->assertFalse($this->booking(['status' => BookingStatus::Complete->value])->customerEmailAllowed());
        $this->assertFalse($this->booking(['status' => BookingStatus::Cancelled->value])->customerEmailAllowed());
    }

    public function test_a_booking_without_an_email_is_not_emailable(): void
    {
        $customer = Customer::factory()->create(['email' => null]);
        $this->assertFalse($this->booking(['customer_id' => $customer->id])->customerEmailAllowed());
    }

    public function test_paid_confirmation_is_suppressed_for_a_past_booking_even_if_paid(): void
    {
        Mail::fake();
        // A past booking that somehow carries a captured payment must still not be
        // emailed a "payment received" confirmation.
        $b = $this->booking(['pickup_at' => now()->subWeek(), 'payment_status' => 'paid']);
        $b->forceFill(['meta' => array_merge($b->meta ?? [], [
            'square_payment' => ['id' => 'pi_x', 'amount' => 50.0, 'at' => now()->toIso8601String()],
        ])])->save();

        app(BookingStatusService::class)->emailPaidConfirmation($b->fresh());

        Mail::assertNotSent(BookingConfirmationMail::class);
    }
}
