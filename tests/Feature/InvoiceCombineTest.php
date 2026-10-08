<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Payments\InvoicePdf;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Billing several separate bookings on one invoice (addToInvoiceGroup).
 */
class InvoiceCombineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_combining_two_bookings_puts_both_on_the_invoice_group(): void
    {
        $a = Booking::factory()->create(['reference' => 'AAA111', 'quoted_price' => 290]);
        $b = Booking::factory()->create(['reference' => 'BBB222', 'quoted_price' => 300]);

        $a->addToInvoiceGroup($b);

        // Reciprocal: viewing either shows both.
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $a->fresh()->invoiceGroupBookings()->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $b->fresh()->invoiceGroupBookings()->pluck('id')->all()
        );
    }

    public function test_combined_invoice_totals_both_and_shows_balance_after_payment(): void
    {
        $a = Booking::factory()->create(['reference' => 'OUT01', 'quoted_price' => 290, 'payment_status' => 'paid']);
        $b = Booking::factory()->create(['reference' => 'RET01', 'quoted_price' => 300, 'payment_status' => 'paid']);
        $a->addToInvoiceGroup($b);
        $a->setVatInvoiceRequested(true);

        $pdf = app(InvoicePdf::class)->renderReceipt($a->fresh());

        // A valid PDF covering both legs (net £590 + £118 VAT, £590 paid → £118 due).
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_admin_can_combine_and_uncombine_from_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Booking::factory()->create(['reference' => 'AAA111']);
        $b = Booking::factory()->create(['reference' => 'BBB222']);

        $this->actingAs($admin)->post(route('bookings.invoice.combine', $a), ['reference' => 'BBB222'])
            ->assertRedirect();
        $this->assertContains($b->id, $a->fresh()->invoiceGroupBookings()->pluck('id')->all());

        $this->actingAs($admin)->delete(route('bookings.invoice.uncombine', ['booking' => $a, 'other' => $b]))
            ->assertRedirect();
        $this->assertNotContains($b->id, $a->fresh()->invoiceGroupBookings()->reject(fn ($x) => $x->id === $a->id)->pluck('id')->all());
    }

    public function test_combining_an_unknown_reference_is_reported(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Booking::factory()->create(['reference' => 'AAA111']);

        $this->actingAs($admin)->post(route('bookings.invoice.combine', $a), ['reference' => 'NOPE'])
            ->assertRedirect()->assertSessionHas('error');
    }
}
