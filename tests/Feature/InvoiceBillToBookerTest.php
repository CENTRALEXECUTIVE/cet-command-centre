<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The invoice "Bill to" is the BOOKER (who paid), not the lead passenger — the
 * lead passenger shows as "Attn:" when they differ.
 */
class InvoiceBillToBookerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_booker_name_defaults_to_the_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Jane Booker']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $this->assertSame('Jane Booker', $booking->bookerName());
    }

    public function test_booker_name_uses_booked_by_when_set(): void
    {
        // Booked for someone else: customer record may be the passenger; the booker
        // is stored in meta['booked_by'].
        $passenger = Customer::factory()->create(['name' => 'Tom Passenger']);
        $booking = Booking::factory()->create([
            'customer_id' => $passenger->id,
            'meta' => ['lead_name' => 'Tom Passenger', 'booked_by' => 'Acme Office'],
        ]);

        $this->assertSame('Acme Office', $booking->bookerName());
        $this->assertSame('Tom Passenger', $booking->displayName());
    }

    public function test_receipt_bills_the_booker_and_notes_the_passenger(): void
    {
        $html = View::make('pdf.receipt', $this->baseData([
            'billedTo' => 'Acme Office',
            'attn' => 'Tom Passenger',
        ]))->render();

        $this->assertStringContainsString('Bill to', $html);
        $this->assertStringContainsString('Acme Office', $html);
        $this->assertStringContainsString('Attn: Tom Passenger', $html);
    }

    public function test_receipt_hides_attn_when_booker_is_the_passenger(): void
    {
        $html = View::make('pdf.receipt', $this->baseData([
            'billedTo' => 'Jane Booker',
            'attn' => 'Jane Booker',
        ]))->render();

        $this->assertStringContainsString('Jane Booker', $html);
        $this->assertStringNotContainsString('Attn:', $html);
    }

    /** @param array<string,mixed> $overrides */
    private function baseData(array $overrides): array
    {
        $booking = Booking::factory()->create(['quoted_price' => 100]);

        return array_merge([
            'booking' => $booking,
            'logo' => null,
            'isCover' => false,
            'isVat' => false,
            'ratePercent' => 0,
            'lines' => [['title' => 'Transfer', 'detail' => 'x', 'net' => 100, 'vat' => 0, 'total' => 100, 'paid' => false]],
            'netTotal' => 100.0,
            'vatTotal' => 0.0,
            'grossTotal' => 100.0,
            'paymentsReceived' => 0.0,
            'balanceDue' => 100.0,
            'attn' => null,
            'billedTo' => 'Someone',
            'customerEmail' => null,
            'customerPhone' => null,
            'invoiceNumber' => 'CET-1',
            'issueDate' => now(),
            'paymentDue' => now()->addDays(14),
            'company' => \App\Support\InvoiceProfile::company(),
            'footerNote' => null,
        ], $overrides);
    }
}
