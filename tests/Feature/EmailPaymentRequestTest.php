<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The office "Email payment request" button: emails the customer a booking-on-hold
 * confirmation with a Complete payment button, and records a "link sent" line.
 */
class EmailPaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    private function booking(?string $email = 'p@example.com'): Booking
    {
        $customer = Customer::create(['name' => 'Pauric Shanley', 'email' => $email, 'phone' => '07700900123']);

        return Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'web',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 105,
            'pickup_at' => now()->addDays(2),
        ]);
    }

    public function test_it_emails_the_customer_an_on_hold_confirmation_with_a_pay_link(): void
    {
        Mail::fake();
        $booking = $this->booking();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.email-payment-request', $booking))
            ->assertRedirect()
            ->assertSessionHas('status');

        // The customer got the confirmation, unpaid, with a pay link.
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($booking)
            && $m->paid === false
            && $m->payUrl !== null);

        // A "link sent" line is recorded for the office.
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'link_sent', 'method' => 'card']);
    }

    public function test_it_errors_cleanly_with_no_customer_email(): void
    {
        Mail::fake();
        $booking = $this->booking(email: null);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.email-payment-request', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_it_is_admin_only(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.email-payment-request', $this->booking()))
            ->assertForbidden();
    }

    public function test_the_confirmation_email_can_be_previewed_without_sending(): void
    {
        Mail::fake();
        $booking = $this->booking();

        $res = $this->actingAs(User::factory()->admin()->create())
            ->get(route('bookings.confirmation-email.preview', $booking))
            ->assertOk();

        // It renders the real confirmation email (the brand + the booking ref show).
        $res->assertSee($booking->reference, false);
        // Previewing never sends anything.
        Mail::assertNothingSent();
    }

    public function test_the_confirmation_preview_is_admin_only(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->get(route('bookings.confirmation-email.preview', $this->booking()))
            ->assertForbidden();
    }
}
