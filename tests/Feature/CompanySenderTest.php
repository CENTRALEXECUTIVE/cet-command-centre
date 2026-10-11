<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two companies stay separate on email too: the VAT company (transfers) sends
 * from its own address; the non-VAT sister (chauffeurs / PVT LTD) sends from the
 * PVT address — but only once PVT sending is enabled, so nothing breaks before
 * that mailbox exists.
 */
class CompanySenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_company_sends_from_the_main_address(): void
    {
        config(['cet.company.email' => 'admin@centralexecutivetransfers.co.uk']);
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'transfers']]);

        $this->assertSame('admin@centralexecutivetransfers.co.uk', $booking->senderAddress());
    }

    public function test_non_vat_booking_still_uses_main_address_until_pvt_is_enabled(): void
    {
        config(['cet.company.email' => 'admin@centralexecutivetransfers.co.uk']);
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        // PVT sending not enabled yet → must NOT try the PVT address (would fail).
        $this->assertSame('admin@centralexecutivetransfers.co.uk', $booking->senderAddress());
    }

    public function test_non_vat_booking_sends_from_the_pvt_address_when_enabled(): void
    {
        config([
            'cet.company.email' => 'admin@centralexecutivetransfers.co.uk',
            'cet.company_novat.email' => 'admin@centralexecutivetransferspvt.co.uk',
        ]);
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        $this->assertSame('admin@centralexecutivetransferspvt.co.uk', $booking->senderAddress());
    }

    public function test_non_vat_booking_routes_to_the_pvt_mailer_when_set_up(): void
    {
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_host', 'smtp.gmail.com', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        $this->assertTrue($booking->usesPvtMailer());
        // The confirmation Mailable picks the dedicated 'pvt' mailer.
        $this->assertSame('pvt', (new \App\Mail\BookingConfirmationMail($booking))->mailer);
    }

    public function test_vat_booking_does_not_use_the_pvt_mailer(): void
    {
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_host', 'smtp.gmail.com', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'transfers']]);

        $this->assertFalse($booking->usesPvtMailer());
        $this->assertNull((new \App\Mail\BookingConfirmationMail($booking))->mailer);
    }

    public function test_the_pvt_mailer_is_configured_from_settings(): void
    {
        Setting::set('mail_pvt_host', 'smtp.gmail.com', 'string', 'mail');
        Setting::set('mail_pvt_port', '587', 'string', 'mail');
        Setting::set('mail_pvt_username', 'admin@centralexecutivetransferspvt.co.uk', 'string', 'mail');
        \App\Support\MailSettings::apply();

        $this->assertSame('smtp', config('mail.mailers.pvt.transport'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.pvt.host'));
        $this->assertSame(587, config('mail.mailers.pvt.port'));
    }
}
