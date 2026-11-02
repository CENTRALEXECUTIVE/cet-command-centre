<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Website VAT-invoice bookings used to store the VAT-INCLUSIVE price, so the pay link
 * added VAT twice (£126 shown → £151.20 charged). Prices are stored EX-VAT now; the
 * cet:web-vat-audit command repairs the older unpaid ones and lists paid over-charges.
 */
class WebVatDoubleChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_a_web_vat_booking_stores_net_and_the_pay_link_charges_the_shown_price(): void
    {
        Mail::fake();
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 1,
            'customer_name' => 'Vat Payer', 'customer_phone' => '07700900888', 'customer_email' => 'vat@example.com',
            'vat_invoice' => '1', 'business_name' => 'Acme Ltd', 'business_address' => '1 Road, Sheffield S1 1AA', 'business_vat_number' => 'GB123456789',
            'payment_method' => 'card',
        ])->assertOk();

        $b = Booking::firstWhere('source', 'web');
        $this->assertEqualsWithDelta(105.0, (float) $b->quoted_price, 0.01, 'stored EX-VAT');
        $this->assertEqualsWithDelta(126.0, (float) $b->amountPayable(), 0.01, 'pay link = £126, NOT £151.20');
        $this->assertTrue((bool) ($b->meta['price_ex_vat'] ?? false));
    }

    private function oldVatBooking(float $stored, array $meta = [], array $attrs = []): Booking
    {
        $c = Customer::create(['name' => 'Old Vat', 'email' => 'old@example.com', 'phone' => '07700900111']);

        return Booking::factory()->create(array_merge([
            'customer_id' => $c->id, 'source' => 'web', 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => $stored, 'pickup_at' => now()->addDays(4),
            'meta' => array_merge(['vat_invoice_requested' => true, 'billing_entity' => 'transfers'], $meta),
        ], $attrs));
    }

    public function test_the_audit_fixes_an_old_unpaid_booking_and_leaves_correct_ones_alone(): void
    {
        $old = $this->oldVatBooking(126.0);
        $new = $this->oldVatBooking(105.0, ['price_ex_vat' => true]);
        $c = Customer::create(['name' => 'No Vat', 'email' => 'nv@example.com', 'phone' => '07700900222']);
        $plain = Booking::factory()->create(['customer_id' => $c->id, 'source' => 'web', 'quoted_price' => 105, 'payment_status' => 'pending']);

        $this->assertEqualsWithDelta(151.2, (float) $old->amountPayable(), 0.01, 'the bug: VAT twice');

        $this->artisan('cet:web-vat-audit')->assertSuccessful(); // report only
        $this->assertEqualsWithDelta(126.0, (float) $old->fresh()->quoted_price, 0.01, 'report-only changes nothing');

        $this->artisan('cet:web-vat-audit', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(105.0, (float) $old->fresh()->quoted_price, 0.01);
        $this->assertEqualsWithDelta(126.0, (float) $old->fresh()->amountPayable(), 0.01, 'pay link now = what they were shown');
        $this->assertEqualsWithDelta(105.0, (float) $new->fresh()->quoted_price, 0.01, 'already-correct booking untouched');
        $this->assertEqualsWithDelta(105.0, (float) $plain->fresh()->quoted_price, 0.01, 'non-VAT booking untouched');

        // Running again is harmless (it's now marked).
        $this->artisan('cet:web-vat-audit', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(105.0, (float) $old->fresh()->quoted_price, 0.01);
    }

    public function test_the_audit_never_changes_a_paid_booking_and_reports_the_over_charge(): void
    {
        $paid = $this->oldVatBooking(126.0, ['square_payment' => ['id' => 'pi_1', 'amount' => 151.2]], ['payment_status' => 'paid']);

        $this->artisan('cet:web-vat-audit', ['--apply' => true])
            ->expectsOutputToContain('OVER-CHARGED £25.20')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(126.0, (float) $paid->fresh()->quoted_price, 0.01, 'paid history is never rewritten');
    }
}
