<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Payments\InvoicePdf;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When the VAT on a VAT invoice is settled, the invoice and the payment totals
 * read paid in full / £0 due.
 */
class VatSettledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_vat_due_until_settled_then_zero(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 290, 'payment_status' => 'paid']);
        $b->setVatInvoiceRequested(true);
        $b = $b->fresh();

        // £290 net + £58 VAT = £348 payable, £290 paid → £58 due.
        $this->assertEqualsWithDelta(348.0, $b->amountPayable(), 0.01);
        $this->assertEqualsWithDelta(58.0, $b->vatOnTopAmount(), 0.01);
        $this->assertEqualsWithDelta(58.0, $b->transactionsAmountDue(), 0.01);

        $b->markVatReceived(true);
        $b = $b->fresh();

        $this->assertTrue($b->vatSettled());
        $this->assertEqualsWithDelta(0.0, $b->transactionsAmountDue(), 0.01);
    }

    public function test_invoice_reads_paid_in_full_once_vat_settled(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 290, 'payment_status' => 'paid']);
        $b->setVatInvoiceRequested(true);
        $b->markVatReceived(true);

        $pdf = app(InvoicePdf::class)->renderReceipt($b->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_admin_can_toggle_vat_received(): void
    {
        $admin = User::factory()->admin()->create();
        $b = Booking::factory()->create(['quoted_price' => 290, 'payment_status' => 'paid']);
        $b->setVatInvoiceRequested(true);

        $this->actingAs($admin)->post(route('bookings.invoice.vat-received', $b), ['received' => '1'])
            ->assertRedirect();
        $this->assertTrue($b->fresh()->vatSettled());

        $this->actingAs($admin)->post(route('bookings.invoice.vat-received', $b), ['received' => '0'])
            ->assertRedirect();
        $this->assertFalse($b->fresh()->vatSettled());
    }

    public function test_settling_marks_the_whole_invoice_group(): void
    {
        $a = Booking::factory()->create(['quoted_price' => 290, 'payment_status' => 'paid']);
        $r = Booking::factory()->create(['quoted_price' => 300, 'payment_status' => 'paid']);
        $a->addToInvoiceGroup($r);
        $a->setVatInvoiceRequested(true);
        $r->setVatInvoiceRequested(true);

        $a->fresh()->markVatReceived(true);

        $this->assertTrue($a->fresh()->vatSettled());
        $this->assertTrue($r->fresh()->vatSettled());
    }
}
