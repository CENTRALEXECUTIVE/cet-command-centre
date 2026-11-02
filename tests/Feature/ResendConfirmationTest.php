<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The payment-history "Resend confirmation" action: re-sends the customer their
 * booking confirmation on demand — the paid receipt when paid, else the pay-link
 * confirmation — preview-then-send, and never to imported/cancelled bookings.
 */
class ResendConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $attrs = []): Booking
    {
        $customer = Customer::create(['name' => 'Re Send', 'email' => 'resend@example.com', 'phone' => '07700900600']);

        return Booking::factory()->create(array_merge([
            'customer_id' => $customer->id, 'source' => 'phone',
            'payment_method' => 'card', 'payment_status' => 'pending',
            'quoted_price' => 90, 'pickup_at' => now()->addDays(2),
            'status' => BookingStatus::Pending,
        ], $attrs));
    }

    public function test_resend_sends_the_pay_link_confirmation_when_unpaid(): void
    {
        Mail::fake();
        $booking = $this->booking();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.resend', $booking))
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($booking)
            && $m->paid === false && $m->payUrl !== null);
    }

    public function test_resend_sends_the_paid_receipt_when_paid(): void
    {
        Mail::fake();
        $booking = $this->booking(['payment_status' => 'paid']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.resend', $booking))
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($booking) && $m->paid === true);
    }

    public function test_resend_works_even_if_one_was_already_sent(): void
    {
        Mail::fake();
        $booking = $this->booking();
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'customer_email_sent' => now()->toIso8601String(),
            'paid_email_sent' => now()->toIso8601String(),
        ])])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.resend', $booking))
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class); // deliberate resend ignores the once-only flags
    }

    public function test_resend_never_emails_an_imported_customer(): void
    {
        Mail::fake();
        $booking = $this->booking(['source_system' => 'eto']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.resend', $booking))
            ->assertRedirect()->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_resend_is_blocked_for_a_cancelled_booking(): void
    {
        Mail::fake();
        $booking = $this->booking(['status' => BookingStatus::Cancelled]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.resend', $booking))
            ->assertRedirect()->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_resend_is_admin_only(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.confirmation-email.resend', $this->booking()))
            ->assertForbidden();
    }
}
