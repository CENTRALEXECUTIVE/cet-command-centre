<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Payments\InvoicePdf;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * 15 Nov 2026: a web customer paid £264 (incl. VAT) for a return trip and the invoice
 * read "Paid £220 · Additional VAT now due £44", and the return leg's invoice billed
 * the passenger instead of their company.
 */
class InvoiceOnlinePaidVatTest extends TestCase
{
    use RefreshDatabase;

    private function capture(Booking $b): array
    {
        $data = [];
        View::creator('pdf.receipt', function ($view) use (&$data) {
            $data = $view->getData();
        });
        app(InvoicePdf::class)->renderReceipt($b->fresh());

        return $data;
    }

    private function trip(): array
    {
        $this->seed(VehicleTypeSeeder::class);
        $vd = ['name' => 'BENDITNOW LTD', 'address' => '7 Bramley Drive', 'vat_number' => '924077525'];
        $out = Booking::factory()->create(['reference' => 'CET-F3979A', 'quoted_price' => 105, 'payment_status' => 'paid', 'meta' => [
            'vat_invoice_requested' => true, 'price_ex_vat' => true, 'vat_details' => $vd,
            'square_payment' => ['id' => 'pay_1', 'amount' => 264.00],
        ]]);
        $ret = Booking::factory()->create(['reference' => 'CET-F3979B', 'quoted_price' => 115, 'payment_status' => 'paid', 'is_return_leg' => true,
            'meta' => ['vat_invoice_requested' => true, 'price_ex_vat' => true]]);
        $out->forceFill(['linked_booking_id' => $ret->id])->save();
        $ret->forceFill(['linked_booking_id' => $out->id])->save();

        return [$out, $ret];
    }

    public function test_a_vat_inclusive_online_payment_reads_paid_in_full(): void
    {
        [$out, $ret] = $this->trip();

        foreach ([$out, $ret] as $leg) {
            $d = $this->capture($leg);
            $this->assertEqualsWithDelta(264.0, $d['grossTotal'], 0.01);
            $this->assertEqualsWithDelta(264.0, $d['paymentsReceived'], 0.01);
            $this->assertEqualsWithDelta(0.0, $d['balanceDue'], 0.01);
        }
    }

    public function test_the_return_leg_invoice_bills_the_company_too(): void
    {
        [, $ret] = $this->trip();

        $d = $this->capture($ret);
        $this->assertSame('BENDITNOW LTD', $d['billedTo']);
        $this->assertSame('924077525', $d['billToVat']);
    }

    public function test_net_only_paid_still_shows_the_vat_due(): void
    {
        [$out] = $this->trip();
        $out->forceFill(['meta' => array_merge($out->meta, ['square_payment' => ['id' => 'pay_1', 'amount' => 220.00]])])->save();

        $d = $this->capture($out);
        $this->assertEqualsWithDelta(220.0, $d['paymentsReceived'], 0.01);
        $this->assertEqualsWithDelta(44.0, $d['balanceDue'], 0.01);
    }

    public function test_the_confirmation_email_shows_the_company_on_a_vat_booking(): void
    {
        [$out] = $this->trip();
        $html = (new \App\Mail\BookingConfirmationMail($out->fresh(), paid: true))->render();
        $this->assertStringContainsString('Company:', $html);
        $this->assertStringContainsString('BENDITNOW LTD', $html);

        $plain = Booking::factory()->create(['quoted_price' => 90]);
        $this->assertStringNotContainsString('Company:', (new \App\Mail\BookingConfirmationMail($plain->fresh()))->render());
    }

    public function test_the_payment_heading_shows_the_whole_trip_payment_from_either_leg(): void
    {
        [$out, $ret] = $this->trip();
        $ret->forceFill(['meta' => array_merge($ret->meta, ['square_payment' => ['id' => 'pay_1', 'amount' => 138.00]])])->save();

        foreach ([$out, $ret] as $leg) {
            $html = (new \App\Mail\BookingConfirmationMail($leg->fresh(), paid: true))->render();
            $this->assertStringContainsString('Payment received: £264.00', $html);
        }
    }
}
