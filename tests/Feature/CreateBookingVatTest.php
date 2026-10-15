<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The New Booking form can mark a booking as a VAT invoice (e.g. a phone booking
 * for a company): it flags VAT, routes to the Ltd/Square entity, and keeps the
 * company details for the invoice.
 */
class CreateBookingVatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Acme Ltd Traveller',
            'customer_phone' => '07700900500',
            'customer_email' => 'travel@acme.co',
            'vehicle_type_id' => VehicleType::first()->id,
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'pickup_address' => '1 Test St, Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'payment_method' => 'card',
            'quoted_price' => 100.00,
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_vat_booking_is_flagged_routed_and_keeps_company_details(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.store'), $this->payload([
            'vat_invoice' => '1',
            'vat_company' => 'Acme Ltd',
            'vat_number' => 'GB123456789',
            'vat_address' => '10 Business Park, Leeds',
        ]))->assertRedirect();

        $booking = \App\Models\Booking::latest('id')->first();
        $this->assertTrue($booking->vatInvoiceRequested());
        $this->assertSame('transfers', $booking->billingEntity()); // Ltd / Square
        $this->assertSame('Acme Ltd', $booking->meta['vat_details']['company']);
        $this->assertSame('GB123456789', $booking->meta['vat_details']['vat_number']);
        $this->assertSame('Acme Ltd', $booking->bookerName()); // invoice bills the company
    }

    public function test_a_normal_booking_is_not_vat(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.store'), $this->payload())->assertRedirect();

        $booking = \App\Models\Booking::latest('id')->first();
        $this->assertFalse($booking->vatInvoiceRequested());
    }
}
