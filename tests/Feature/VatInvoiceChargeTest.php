<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A private customer who asks for a VAT invoice: prices are ex-VAT, so VAT (20%)
 * is added ON TOP, the payable total becomes net + VAT, and the PDF is a proper
 * VAT invoice.
 */
class VatInvoiceChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.vat_registered' => true, 'cet.vat_rate' => 0.20]);
    }

    public function test_turning_on_vat_adds_20_percent_on_top(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 100.00, 'payment_method' => 'card']);

        $this->assertFalse($booking->vatInvoiceRequested());
        $this->assertSame(100.0, $booking->amountPayable());

        $this->actingAs($admin)->post(route('bookings.vat-invoice', $booking), ['vat' => '1'])
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertTrue($booking->vatInvoiceRequested());
        $b = $booking->fareVatBreakdown();
        $this->assertSame(100.0, $b['net']);
        $this->assertSame(20.0, $b['vat']);
        $this->assertSame(120.0, $b['gross']);
        $this->assertSame(120.0, $booking->amountPayable());
    }

    public function test_the_vat_invoice_pdf_renders(): void
    {
        $booking = Booking::factory()->create(['quoted_price' => 100.00, 'payment_method' => 'card']);
        $booking->setVatInvoiceRequested(true);

        $pdf = app(\App\Services\Payments\InvoicePdf::class)->renderReceipt($booking->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_the_booking_page_shows_the_vat_toggle_and_total(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 100.00, 'payment_method' => 'card']);
        $booking->setVatInvoiceRequested(true);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Charge VAT', false)
            ->assertSee('120.00', false);
    }
}
