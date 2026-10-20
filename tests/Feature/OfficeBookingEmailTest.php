<?php

namespace Tests\Feature;

use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The office (ops inbox) is notified at each step: a new booking is created,
 * and later when its fare is paid online.
 */
class OfficeBookingEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cet.ops_email' => 'office@example.com']);
    }

    public function test_the_office_is_emailed_a_new_booking(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        app(BookingStatusService::class)->emailOffice($booking->fresh(), 'new');

        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'new'
            && $m->hasTo('office@example.com')
            && $m->booking->id === $booking->id);
        $this->assertNotEmpty($booking->fresh()->meta['office_email_new_sent'] ?? null);
    }

    public function test_the_office_is_emailed_on_payment(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'paid']);

        app(BookingStatusService::class)->emailOffice($booking->fresh(), 'paid');

        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid'
            && $m->hasTo('office@example.com'));
    }

    public function test_an_unpaid_card_booking_reads_as_incomplete_awaiting_payment(): void
    {
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 115,
        ]);

        $mail = new OfficeBookingMail($booking->fresh(), 'new');
        $this->assertStringContainsString('Incomplete booking (awaiting payment)', $mail->envelope()->subject);

        $html = $mail->render();
        $this->assertStringContainsString('awaiting payment', $html);
        $this->assertStringContainsString('not yet confirmed', $html);
    }

    public function test_when_a_fare_is_paid_the_office_gets_the_paid_email(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'cust@example.com']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'phone',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 115,
        ]);
        // A real provider payment lands (webhook) — mark it and run the paid flow.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'square_payment' => ['id' => 'pi_1', 'amount' => 115.0, 'at' => now()->toIso8601String()],
        ])])->save();

        app(BookingStatusService::class)->onFarePaid($booking->fresh());

        // Office gets the "payment received" email, customer gets the paid confirmation.
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid');
        Mail::assertSent(\App\Mail\BookingConfirmationMail::class, fn ($m) => $m->paid === true);
        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_the_paid_office_email_attaches_the_invoice_but_the_new_one_does_not(): void
    {
        $customer = Customer::factory()->create(['email' => 'cust@example.com']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 140,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds LS1 1AA',
        ]);

        // The "new/awaiting" email carries no invoice (nothing paid yet).
        $this->assertCount(0, (new OfficeBookingMail($booking->fresh(), 'new'))->attachments());

        // Once paid, the office copy carries the same invoice PDF the customer gets.
        $booking->forceFill(['payment_status' => 'paid'])->save();
        $this->assertCount(1, (new OfficeBookingMail($booking->fresh(), 'paid'))->attachments());
    }

    public function test_each_office_stage_is_sent_once(): void
    {
        Mail::fake();
        $booking = Booking::factory()->create();
        $svc = app(BookingStatusService::class);

        $svc->emailOffice($booking->fresh(), 'new');
        $svc->emailOffice($booking->fresh(), 'new');
        $svc->emailOffice($booking->fresh(), 'paid');

        // 'new' once, 'paid' once — the repeat 'new' is suppressed.
        Mail::assertSent(OfficeBookingMail::class, 2);
    }

    public function test_no_office_email_when_no_ops_inbox_is_set(): void
    {
        config(['cet.ops_email' => null]);
        Mail::fake();
        $booking = Booking::factory()->create();

        app(BookingStatusService::class)->emailOffice($booking->fresh(), 'new');

        Mail::assertNothingSent();
    }
}
