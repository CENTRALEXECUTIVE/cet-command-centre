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
}
