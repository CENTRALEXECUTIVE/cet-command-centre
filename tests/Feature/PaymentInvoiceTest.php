<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use App\Services\Payments\InvoiceService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    private function book(array $overrides): Booking
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();

        return app(BookingService::class)->createFromForm(array_merge([
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $executive->id,
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2)->format('Y-m-d H:i'),
            'pickup_address' => 'Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'quoted_price' => 120,
            'privacy_consent' => '1',
        ], $overrides), $admin);
    }

    public function test_card_booking_generates_a_tide_payment_link(): void
    {
        $booking = $this->book(['payment_method' => 'card']);

        $payment = $booking->payments()->first();
        $this->assertEquals('card', $payment->method);
        $this->assertEquals('link_sent', $payment->status);
        $this->assertNotEmpty($payment->tide_payment_link);
        $this->assertStringContainsString($booking->reference, $payment->tide_payment_link);
    }

    public function test_cash_booking_is_flagged_separately_without_a_link(): void
    {
        $booking = $this->book(['payment_method' => 'cash']);

        $payment = $booking->payments()->first();
        $this->assertEquals('cash', $payment->method);
        $this->assertEquals('pending', $payment->status);
        $this->assertNull($payment->tide_payment_link);
    }

    public function test_account_bookings_are_invoiced_with_vat(): void
    {
        $account = CorporateAccount::create([
            'name' => 'JELD-WEN', 'slug' => 'jeld-wen', 'account_code' => 'JW',
            'cost_code_required' => true, 'is_active' => true, 'payment_terms_days' => 30,
        ]);

        $booking = $this->book([
            'payment_method' => 'account',
            'corporate_account_id' => $account->id,
            'cost_code' => 'CC-100',
        ]);
        // Mark completed in last month so it is invoiceable.
        $booking->update(['status' => BookingStatus::Complete->value, 'pickup_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)]);

        $start = now()->subMonthNoOverflow()->startOfMonth();
        $end = now()->subMonthNoOverflow()->endOfMonth();
        $invoice = app(InvoiceService::class)->generateForAccount($account, $start, $end);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals(120.00, (float) $invoice->subtotal);
        $this->assertEquals(24.00, (float) $invoice->vat_amount); // 20%
        $this->assertEquals(144.00, (float) $invoice->total);
        $this->assertEquals('invoiced', $booking->fresh()->payment_status);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'cost_code' => 'CC-100']);
    }

    public function test_admin_can_generate_a_months_invoices_from_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CorporateAccount::create([
            'name' => 'LB Foster', 'slug' => 'lb-foster', 'account_code' => 'LBF', 'is_active' => true,
        ]);
        $booking = $this->book(['payment_method' => 'account', 'corporate_account_id' => $account->id]);
        $booking->update(['status' => BookingStatus::Complete->value, 'pickup_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)]);

        $month = now()->subMonthNoOverflow()->format('Y-m');
        $this->actingAs($admin)->post(route('invoices.generate'), ['month' => $month])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, Invoice::where('corporate_account_id', $account->id)->count());
    }

    public function test_generating_a_quiet_month_reports_nothing_rather_than_erroring(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('invoices.generate'), ['month' => now()->subMonthNoOverflow()->format('Y-m')])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame(0, Invoice::count());
    }
}
