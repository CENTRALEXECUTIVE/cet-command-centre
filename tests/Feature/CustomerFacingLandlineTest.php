<?php

namespace Tests\Feature;

use App\Mail\BookingCancellationMail;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\InvoiceProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directors (11 Nov 2026): booking confirmations, cancellations and invoices show
 * the office LANDLINE (0114 399 8242), never the mobile.
 */
class CustomerFacingLandlineTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        $customer = Customer::create(['name' => 'Jo Smith', 'email' => 'jo@example.com', 'phone' => '07700900123']);

        return Booking::factory()->create(['customer_id' => $customer->id, 'quoted_price' => 100]);
    }

    public function test_confirmation_and_cancellation_show_the_landline_not_the_mobile(): void
    {
        $booking = $this->booking();

        foreach ([new BookingConfirmationMail($booking), new BookingCancellationMail($booking)] as $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('0114 399 8242', $html);
            $this->assertStringNotContainsString('7405172435', $html);
            $this->assertStringNotContainsString('7405 172435', $html);
        }
    }

    public function test_invoice_company_phone_defaults_to_the_landline(): void
    {
        $this->assertSame('0114 399 8242', InvoiceProfile::company()['phone']);
    }
}
