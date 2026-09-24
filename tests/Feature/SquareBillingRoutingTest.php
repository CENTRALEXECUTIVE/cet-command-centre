<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Payments\SquareBookingPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VAT-invoice customers are billed by Central Executive Transfers; everyone else
 * (no VAT) is taken by the sister company Central Executive Chauffeurs — the
 * payment goes to that Square account so the money lands in its bank. Branding is
 * unchanged either way.
 */
class SquareBillingRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.square.environment' => 'production',
            'services.square.access_token' => 'TRANSFERS_TOKEN',
            'services.square.location_id' => 'TRANSFERS_LOC',
            'services.square.webhook_signature_key' => 'transfers-key',
            'services.square_chauffeurs.environment' => 'production',
            'services.square_chauffeurs.access_token' => 'CHAUFFEURS_TOKEN',
            'services.square_chauffeurs.location_id' => 'CHAUFFEURS_LOC',
            'services.square_chauffeurs.webhook_signature_key' => 'chauffeurs-key',
        ]);
    }

    private function booking(bool $vatInvoice): Booking
    {
        return Booking::factory()->create([
            'meta' => ['billing_entity' => $vatInvoice ? 'transfers' : 'chauffeurs', 'vat_invoice_requested' => $vatInvoice],
        ]);
    }

    public function test_the_billing_entity_follows_the_vat_choice(): void
    {
        $this->assertSame('transfers', $this->booking(true)->billingEntity());
        $this->assertSame('chauffeurs', $this->booking(false)->billingEntity());
    }

    public function test_a_no_vat_booking_falls_back_to_transfers_when_the_sister_account_is_unset(): void
    {
        config(['services.square_chauffeurs.access_token' => null, 'services.square_chauffeurs.location_id' => null]);
        // No stored entity → computed live; sister not configured → transfers.
        $b = Booking::factory()->create(['meta' => ['vat_invoice_requested' => false]]);
        $this->assertSame('transfers', $b->billingEntity());
    }

    public function test_a_no_vat_fare_checkout_uses_the_sister_square_account(): void
    {
        Http::fake(['connect.squareup.com/*' => Http::response(['payment_link' => ['url' => 'https://sq/checkout']])]);

        app(SquareBookingPaymentService::class)->createCheckoutUrl($this->booking(false), 95.00);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'payment-links')
            && $r->hasHeader('Authorization', 'Bearer CHAUFFEURS_TOKEN')
            && data_get($r->data(), 'order.location_id') === 'CHAUFFEURS_LOC');
    }

    public function test_a_vat_fare_checkout_uses_the_transfers_square_account(): void
    {
        Http::fake(['connect.squareup.com/*' => Http::response(['payment_link' => ['url' => 'https://sq/checkout']])]);

        app(SquareBookingPaymentService::class)->createCheckoutUrl($this->booking(true), 95.00);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'payment-links')
            && $r->hasHeader('Authorization', 'Bearer TRANSFERS_TOKEN')
            && data_get($r->data(), 'order.location_id') === 'TRANSFERS_LOC');
    }

    public function test_the_sister_webhook_records_a_fare_against_the_chauffeurs_account(): void
    {
        $b = $this->booking(false);
        $ref = $b->reference;
        Http::fake([
            'connect.squareup.com/v2/orders/*' => Http::response(['order' => ['reference_id' => 'FARE-'.$ref]]),
        ]);

        $payload = ['data' => ['object' => ['payment' => [
            'id' => 'PAY_1', 'status' => 'COMPLETED', 'order_id' => 'ORD_1',
            'amount_money' => ['amount' => 9500],
        ]]]];

        $marked = app(SquareBookingPaymentService::class)->recordFareFromWebhook($payload, 'chauffeurs');

        $this->assertNotNull($marked);
        $b->refresh();
        $this->assertSame('paid', $b->payment_status);
        $this->assertSame('chauffeurs', $b->meta['square_payment']['entity']);
        // The order lookup used the sister account's token.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/orders/')
            && $r->hasHeader('Authorization', 'Bearer CHAUFFEURS_TOKEN'));
    }
}
