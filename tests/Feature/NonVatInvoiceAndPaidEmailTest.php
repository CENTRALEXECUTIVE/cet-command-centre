<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingStatusService;
use App\Services\Payments\BookingInvoicePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NonVatInvoiceAndPaidEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_vat_invoice_is_pvt_ltd_with_no_vat(): void
    {
        $booking = Booking::factory()->create(['quoted_price' => 100]); // no VAT selected
        $data = app(BookingInvoicePdf::class)->data($booking);

        $this->assertFalse($data['isVat']);
        $this->assertSame('Central Executive Transfers PVT LTD', $data['company']['name']);
        $this->assertSame('', $data['company']['vat_number']);
        $this->assertEqualsWithDelta(0.0, $data['vatAmount'], 0.01);
        $this->assertEqualsWithDelta(100.0, $data['gross'], 0.01);
        $this->assertEqualsWithDelta(100.0, $data['net'], 0.01); // net = gross, no split
    }

    public function test_a_vat_invoice_still_shows_vat_and_the_ltd(): void
    {
        $booking = Booking::factory()->create(['quoted_price' => 100]);
        $booking->setVatInvoiceRequested(true);
        $data = app(BookingInvoicePdf::class)->data($booking->fresh());

        $this->assertTrue($data['isVat']);
        $this->assertSame('Central Executive Transfers Ltd', $data['company']['name']);
        $this->assertEqualsWithDelta(20.0, $data['vatAmount'], 0.01); // £100 + 20%
        $this->assertEqualsWithDelta(120.0, $data['gross'], 0.01);
    }

    public function test_the_invoice_is_attached_only_once_paid(): void
    {
        $booking = Booking::factory()->create(['quoted_price' => 100]);

        $this->assertCount(0, (new BookingConfirmationMail($booking, paid: false))->attachments());
        $this->assertCount(1, (new BookingConfirmationMail($booking, paid: true))->attachments());
    }

    public function test_paid_confirmation_emails_an_office_made_booking(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'cust@example.com']);
        // An office-made (non-web) booking, already sent a pending confirmation.
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'phone', 'payment_status' => 'paid',
        ]);
        $booking->forceFill(['meta' => ['customer_email_sent' => now()->toIso8601String()]])->save();

        app(BookingStatusService::class)->emailPaidConfirmation($booking->fresh());

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->paid && $m->hasTo('cust@example.com'));
        $this->assertNotEmpty($booking->fresh()->meta['paid_email_sent'] ?? null);
    }

    public function test_paid_confirmation_is_sent_once(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'cust@example.com']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'paid']);

        $svc = app(BookingStatusService::class);
        $svc->emailPaidConfirmation($booking->fresh());
        $svc->emailPaidConfirmation($booking->fresh());

        Mail::assertSent(BookingConfirmationMail::class, 1);
    }
}
