<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Payments\StripePaymentService;
use App\Support\InvoiceProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sister company, Central Executive Transfers PVT LTD (non-VAT), takes its
 * fares through Stripe. A VAT customer stays on Square (Central Executive
 * Transfers Ltd). These lock the Stripe link, webhook and entity routing.
 */
class StripeSisterPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function enableStripe(): void
    {
        config([
            'services.stripe.secret_key' => 'sk_test_123',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);
    }

    public function test_it_creates_a_stripe_checkout_link(): void
    {
        $this->enableStripe();
        Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/pay/cs_test_1'], 200)]);

        $booking = Booking::factory()->create(['reference' => 'CET-NOVAT']);
        $url = app(StripePaymentService::class)->createCheckoutUrl($booking, 120.0, 'https://app/redirect', 'Journey fare');

        $this->assertSame('https://checkout.stripe.com/pay/cs_test_1', $url);
        // Amount is in pence, with our FARE reference on the session.
        Http::assertSent(function ($req) {
            $body = urldecode(http_build_query($req->data()));
            return str_contains($body, 'unit_amount]=12000')
                && str_contains($body, 'FARE-CET-NOVAT');
        });
    }

    public function test_it_is_a_silent_noop_until_configured(): void
    {
        config(['services.stripe.secret_key' => null]);
        $booking = Booking::factory()->create();

        $this->assertFalse(app(StripePaymentService::class)->enabled());
        $this->assertNull(app(StripePaymentService::class)->createCheckoutUrl($booking, 100.0));
    }

    public function test_a_completed_checkout_marks_the_fare_paid(): void
    {
        $this->enableStripe();
        $booking = Booking::factory()->create(['reference' => 'CET-NOVAT', 'payment_status' => 'pending']);

        $paid = app(StripePaymentService::class)->recordFareFromWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_123',
                'amount_total' => 12000,
                'client_reference_id' => 'FARE-CET-NOVAT',
                'metadata' => ['reference' => 'CET-NOVAT'],
            ]],
        ]);

        $this->assertNotNull($paid);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertSame('chauffeurs', $booking->fresh()->meta['square_payment']['entity']);
    }

    public function test_an_unpaid_or_foreign_event_is_ignored(): void
    {
        $this->enableStripe();
        Booking::factory()->create(['reference' => 'CET-NOVAT']);
        $svc = app(StripePaymentService::class);

        $this->assertNull($svc->recordFareFromWebhook(['type' => 'payment_intent.created', 'data' => ['object' => []]]));
        $this->assertNull($svc->recordFareFromWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['payment_status' => 'unpaid', 'client_reference_id' => 'FARE-CET-NOVAT', 'amount_total' => 12000]],
        ]));
    }

    public function test_webhook_signature_is_verified(): void
    {
        $this->enableStripe();
        $svc = app(StripePaymentService::class);
        $payload = '{"hello":"world"}';
        $t = time();
        $good = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');

        $this->assertTrue($svc->verifyWebhook($payload, $good));
        $this->assertFalse($svc->verifyWebhook($payload, 't='.$t.',v1=deadbeef'));
        $this->assertFalse($svc->verifyWebhook($payload, null));
        // Outside the tolerance window.
        $stale = 't='.($t - 10000).',v1='.hash_hmac('sha256', ($t - 10000).'.'.$payload, 'whsec_test');
        $this->assertFalse($svc->verifyWebhook($payload, $stale));
    }

    public function test_the_webhook_route_records_a_paid_session(): void
    {
        $this->enableStripe();
        $booking = Booking::factory()->create(['reference' => 'CET-NOVAT', 'payment_status' => 'pending']);

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_1',
                'amount_total' => 9000, 'client_reference_id' => 'FARE-CET-NOVAT',
                'metadata' => ['reference' => 'CET-NOVAT'],
            ]],
        ]);
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk()->assertJson(['recorded' => true]);

        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->enableStripe();
        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=nope', 'CONTENT_TYPE' => 'application/json',
        ], '{"type":"checkout.session.completed"}')->assertStatus(403);
    }

    public function test_entity_routes_novat_to_the_sister_when_stripe_is_on(): void
    {
        $this->enableStripe();
        $novat = Booking::factory()->create();
        $novat->forceFill(['meta' => ['vat_invoice_requested' => false]])->save();
        $vat = Booking::factory()->create();
        $vat->forceFill(['meta' => ['vat_invoice_requested' => true]])->save();

        $this->assertSame('chauffeurs', $novat->fresh()->billingEntity());
        $this->assertSame('transfers', $vat->fresh()->billingEntity());
    }

    public function test_toggling_vat_reroutes_the_billing_entity(): void
    {
        $this->enableStripe();
        // Stamped as the sister at creation (a no-VAT web booking).
        $booking = Booking::factory()->create();
        $booking->forceFill(['meta' => ['billing_entity' => 'chauffeurs']])->save();

        // Office clicks "VAT invoice" → must move to the Ltd / Square entity.
        $booking->setVatInvoiceRequested(true);
        $this->assertSame('transfers', $booking->fresh()->billingEntity());

        // Office clicks "No VAT" → back to the sister (Stripe is configured).
        $booking->fresh()->setVatInvoiceRequested(false);
        $this->assertSame('chauffeurs', $booking->fresh()->billingEntity());
    }

    public function test_no_vat_falls_back_to_the_ltd_when_no_sister_provider(): void
    {
        config(['services.stripe.secret_key' => null, 'services.square_chauffeurs.access_token' => null]);
        $booking = Booking::factory()->create();

        $booking->setVatInvoiceRequested(false);
        // No sister provider configured → stays on the VAT company so a link can
        // still be made via Square.
        $this->assertSame('transfers', $booking->fresh()->billingEntity());
    }

    public function test_novat_invoice_profile_is_pvt_ltd_without_vat(): void
    {
        $vat = InvoiceProfile::companyFor(true);
        $novat = InvoiceProfile::companyFor(false);

        $this->assertSame('Central Executive Transfers Ltd', $vat['name']);
        $this->assertSame('Central Executive Transfers PVT LTD', $novat['name']);
        $this->assertSame('', $novat['vat_number']); // PVT LTD is not VAT registered
    }
}
