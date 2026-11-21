<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\PaymentLinkMail;
use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 21 Nov 2026 (CET-4DA06B): Settings said non-VAT emails send "via Resend" from the
 * PVT address, but Mail::to()->send() swapped every booking email back to the main
 * (Microsoft / Ltd) mailbox. Real sends — no Mail::fake — must use the PVT mailer.
 */
class PvtEmailActuallyUsesPvtMailerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_resend_key', 're_test', 'string', 'mail');
        Setting::set('mail_pvt_from_address', 'admin@pvt.example', 'string', 'mail');
        config([
            'mail.default' => 'main',
            'mail.mailers.main' => ['transport' => 'array'],
            'mail.mailers.resend' => ['transport' => 'array'],
        ]);
    }

    private function sent(string $mailer): int
    {
        return count(Mail::mailer($mailer)->getSymfonyTransport()->messages());
    }

    public function test_a_non_vat_payment_link_goes_through_the_pvt_mailer(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 100, 'meta' => ['billing_entity' => 'chauffeurs']]);
        $this->assertSame('resend', $b->pvtMailer());

        Mail::to('customer@example.com')->send(new PaymentLinkMail($b, 35.0, 'https://buy.stripe.com/x'));
        Mail::to('customer@example.com')->send(new BookingConfirmationMail($b));

        $this->assertSame(2, $this->sent('resend'));
        $this->assertSame(0, $this->sent('main'));
    }

    public function test_a_vat_booking_still_uses_the_main_mailer(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 100, 'meta' => ['billing_entity' => 'transfers', 'vat_invoice_requested' => true]]);

        Mail::to('customer@example.com')->send(new PaymentLinkMail($b, 120.0, 'https://square.link/x'));

        $this->assertSame(0, $this->sent('resend'));
        $this->assertSame(1, $this->sent('main'));
    }
}
