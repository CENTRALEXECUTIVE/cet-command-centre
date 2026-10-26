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
 * When a provider webhook is missed, the office can record the payment by hand and
 * it must behave like a real payment: switch the booking to paid AND send the
 * customer the paid receipt + the office the "payment received" notice.
 */
class ManualMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    private function unpaidCardBooking(): Booking
    {
        $customer = Customer::create(['name' => 'Jo Payer', 'email' => 'jo@example.com', 'phone' => '07700900500']);

        return Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'phone',
            'payment_method' => 'card', 'payment_status' => 'pending',
            'quoted_price' => 120, 'pickup_at' => now()->addDays(2),
            'status' => \App\Enums\BookingStatus::Pending,
        ]);
    }

    public function test_adding_a_paid_transaction_settles_the_booking_and_emails_the_receipt(): void
    {
        Mail::fake();
        $booking = $this->unpaidCardBooking();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.transactions.store', $booking), [
                'name' => 'Balance', 'amount' => '120.00', 'method' => 'card', 'status' => 'paid',
            ])->assertRedirect();

        $this->assertTrue($booking->fresh()->fareIsPaid());
        $this->assertSame('paid', $booking->fresh()->payment_status);
        // The customer gets the PAID confirmation, just like a webhook payment.
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($booking) && $m->paid === true);
    }

    public function test_marking_an_existing_transaction_paid_settles_and_emails(): void
    {
        Mail::fake();
        $booking = $this->unpaidCardBooking();
        $payment = $booking->payments()->create([
            'method' => 'card', 'amount' => 120, 'status' => 'link_sent',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.transactions.pay-now', [$booking, $payment]))
            ->assertRedirect();

        $this->assertTrue($booking->fresh()->fareIsPaid());
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($booking) && $m->paid === true);
    }

    public function test_the_main_mark_paid_button_settles_and_emails(): void
    {
        Mail::fake();
        $booking = $this->unpaidCardBooking();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('payments.paid', $booking))
            ->assertRedirect();

        $this->assertSame('paid', $booking->fresh()->payment_status);
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($booking) && $m->paid === true);
    }

    public function test_an_imported_eto_customer_is_never_emailed_on_mark_paid(): void
    {
        Mail::fake();
        $customer = Customer::create(['name' => 'Old ETO', 'email' => 'old@example.com', 'phone' => '07700900900']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'phone', 'source_system' => 'eto',
            'payment_method' => 'card', 'payment_status' => 'pending',
            'quoted_price' => 80, 'pickup_at' => now()->addDays(2),
            'status' => \App\Enums\BookingStatus::Pending,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('payments.paid', $booking))->assertRedirect();

        // Status still settles, but the imported customer is NOT emailed.
        $this->assertSame('paid', $booking->fresh()->payment_status);
        Mail::assertNotSent(BookingConfirmationMail::class);
    }

    public function test_a_partial_payment_does_not_send_a_paid_receipt(): void
    {
        Mail::fake();
        $booking = $this->unpaidCardBooking(); // £120 fare

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.transactions.store', $booking), [
                'name' => 'Deposit', 'amount' => '30.00', 'method' => 'card', 'status' => 'paid',
            ])->assertRedirect();

        // Not fully paid → no "paid" receipt yet.
        $this->assertFalse($booking->fresh()->fareIsPaid());
        Mail::assertNotSent(BookingConfirmationMail::class);
    }
}
