<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A non-VAT (PVT LTD) booking's emails — the customer confirmation AND the office
 * notification — must send through the PVT mailer and from the PVT address, not
 * the normal VAT-company account.
 */
class PvtMailRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const PVT_FROM = 'admin@centralexecutivetransferspvt.co.uk';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_host', 'smtp.pvt.example', 'string', 'mail');
        Setting::set('mail_pvt_from_address', self::PVT_FROM, 'string', 'mail');
    }

    private function booking(string $entity): Booking
    {
        $customer = Customer::create(['name' => 'Pauric Shanley', 'email' => 'p@example.com', 'phone' => '00353860889895']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'web',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 105,
            'pickup_at' => now()->addDays(2),
        ]);
        $b->forceFill(['meta' => array_merge($b->meta ?? [], ['billing_entity' => $entity])])->save();

        return $b->fresh();
    }

    public function test_the_customer_confirmation_routes_to_pvt(): void
    {
        $mail = new BookingConfirmationMail($this->booking('chauffeurs'));

        // A PVT mailer (resend/gmail/pvt — whichever is set up), from the PVT address.
        $this->assertContains($mail->mailer, ['resend', 'gmail', 'pvt']);
        $this->assertSame(self::PVT_FROM, $mail->envelope()->from->address);
    }

    public function test_the_office_notification_routes_to_pvt(): void
    {
        $mail = new OfficeBookingMail($this->booking('chauffeurs'), 'new');

        $this->assertContains($mail->mailer, ['resend', 'gmail', 'pvt']);
        $this->assertSame(self::PVT_FROM, $mail->envelope()->from->address);
    }

    public function test_a_vat_booking_stays_on_the_main_account(): void
    {
        config(['cet.company.email' => 'admin@centralexecutivetransfers.co.uk', 'cet.office_from' => 'office@centralexecutivetransfers.co.uk']);

        $customerMail = new BookingConfirmationMail($this->booking('transfers'));
        $officeMail = new OfficeBookingMail($this->booking('transfers'), 'new');

        // No PVT mailer, and the from-address is the main company's, not PVT.
        $this->assertNull($customerMail->mailer);
        $this->assertNull($officeMail->mailer);
        $this->assertNotSame(self::PVT_FROM, $customerMail->envelope()->from->address);
        $this->assertNotSame(self::PVT_FROM, $officeMail->envelope()->from->address);
    }
}
