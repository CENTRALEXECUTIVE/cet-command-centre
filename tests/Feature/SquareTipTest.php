<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Payments\SquareTipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SquareTipTest extends TestCase
{
    use RefreshDatabase;

    private function configureSquare(): void
    {
        config([
            'services.square.access_token' => 'sq-test-token',
            'services.square.location_id' => 'L123',
            'services.square.webhook_signature_key' => 'sig-key',
            'services.square.environment' => 'sandbox',
            'services.square.tip_amounts' => [5, 10, 20],
        ]);
    }

    public function test_tip_page_shows_amounts_and_driver_when_square_is_on(): void
    {
        $this->configureSquare();
        $driver = User::factory()->driver()->create(['name' => 'Majid Ali']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);

        $this->get(route('tip.show', $booking->tipToken()))
            ->assertOk()
            ->assertSee('Majid')          // driver first name
            ->assertSee('£5')->assertSee('£10')->assertSee('£20');
    }

    public function test_each_preset_amount_is_its_own_form_so_it_submits_cleanly(): void
    {
        // Regression: presets and the custom box shared one form + the name
        // "amount", so tapping a preset was overridden by the empty box and sent
        // nothing. Each preset must be its own form (3 presets + 1 custom = 4).
        $this->configureSquare();
        $booking = Booking::factory()->create();
        $token = $booking->tipToken();

        $html = $this->get(route('tip.show', $token))->assertOk()->getContent();

        $this->assertEquals(4, substr_count($html, 'action="'.route('tip.pay', $token).'"'));
    }

    public function test_tip_page_is_dormant_when_square_is_off(): void
    {
        config(['services.square.access_token' => null, 'services.square.location_id' => null]);
        $booking = Booking::factory()->create();

        $this->get(route('tip.show', $booking->tipToken()))
            ->assertOk()
            ->assertSee('aren’t available just yet', false);
    }

    public function test_tip_token_resolves_via_the_indexed_column_and_a_legacy_meta_token(): void
    {
        // New tokens live in the indexed column and resolve reliably.
        $booking = Booking::factory()->create();
        $token = $booking->tipToken();
        $this->assertSame($token, $booking->fresh()->tip_token);           // stored on the column
        $this->assertSame($booking->id, Booking::byTipToken($token)?->id); // resolves

        // A link sent before the column existed (token only in meta) still resolves.
        $legacy = Booking::factory()->create();
        $legacy->forceFill(['tip_token' => null, 'meta' => ['tip_token' => 'legacy123x']])->save();
        $this->assertSame($legacy->id, Booking::byTipToken('legacy123x')?->id);
    }

    public function test_unknown_token_shows_a_friendly_page_not_a_404(): void
    {
        $this->get(route('tip.show', 'nope'))->assertOk()->assertSee('Link not found');
    }

    public function test_choosing_an_amount_creates_a_square_link_and_redirects_there(): void
    {
        $this->configureSquare();
        Http::fake([
            '*/v2/online-checkout/payment-links' => Http::response([
                'payment_link' => ['id' => 'pl_1', 'url' => 'https://squareup.com/checkout/abc'],
            ], 200),
        ]);
        $booking = Booking::factory()->create(['external_reference' => 'A078B2']);

        $this->post(route('tip.pay', $booking->tipToken()), ['amount' => '10'])
            ->assertRedirect('https://squareup.com/checkout/abc');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'payment-links')
                // The order is stamped with the TIP- prefix so the webhook can
                // tell a genuine tip from a fare payment.
                && $request['order']['reference_id'] === 'TIP-A078B2'
                && $request['order']['line_items'][0]['base_price_money']['amount'] === 1000; // £10 in pennies
        });
    }

    public function test_webhook_logs_a_completed_tip_onto_the_booking(): void
    {
        $this->configureSquare();
        $driver = User::factory()->driver()->create(['name' => 'Majid Ali']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'external_reference' => 'A078B2']);

        // The service fetches the order to read its reference (TIP-prefixed).
        Http::fake([
            '*/v2/orders/order_9' => Http::response(['order' => ['reference_id' => 'TIP-A078B2']], 200),
        ]);

        $payload = [
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'pay_1', 'order_id' => 'order_9', 'status' => 'COMPLETED',
                'amount_money' => ['amount' => 1000, 'currency' => 'GBP'],
            ]]],
        ];

        $booking = app(SquareTipService::class)->recordTipFromWebhook($payload);

        $this->assertNotNull($booking);
        $this->assertSame(10.0, $booking->fresh()->cardTipsOwed());
    }

    public function test_a_fare_payment_is_not_logged_as_a_tip(): void
    {
        $this->configureSquare();
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'external_reference' => 'A078B2']);

        // A fare payment resolves to the booking but its order has NO TIP- prefix.
        Http::fake([
            '*/v2/orders/order_fare' => Http::response(['order' => ['reference_id' => 'A078B2']], 200),
        ]);

        $result = app(SquareTipService::class)->recordTipFromWebhook([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'pay_fare', 'order_id' => 'order_fare', 'status' => 'COMPLETED',
                'amount_money' => ['amount' => 65000, 'currency' => 'GBP'], // £650 fare
            ]]],
        ]);

        $this->assertNull($result);
        $this->assertSame(0.0, $booking->fresh()->tipsTotal());
    }

    public function test_webhook_is_idempotent_on_a_redelivered_payment(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create(['external_reference' => 'A078B2']);
        Http::fake(['*/v2/orders/*' => Http::response(['order' => ['reference_id' => 'TIP-A078B2']], 200)]);

        $payload = [
            'data' => ['object' => ['payment' => [
                'id' => 'pay_dup', 'order_id' => 'order_1', 'status' => 'COMPLETED',
                'amount_money' => ['amount' => 500],
            ]]],
        ];

        $svc = app(SquareTipService::class);
        $this->assertNotNull($svc->recordTipFromWebhook($payload));   // first time logs it
        $this->assertNull($svc->recordTipFromWebhook($payload));       // second time is a no-op
        $this->assertSame(5.0, $booking->fresh()->tipsTotal());        // counted once
    }

    public function test_a_refund_reverses_the_tip_off_the_payroll(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create(['external_reference' => 'A078B2']);
        Http::fake(['*/v2/orders/*' => Http::response(['order' => ['reference_id' => 'TIP-A078B2']], 200)]);
        $svc = app(SquareTipService::class);

        // Tip comes in…
        $svc->recordTipFromWebhook(['data' => ['object' => ['payment' => [
            'id' => 'pay_ref', 'order_id' => 'order_1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 1000],
        ]]]]);
        $this->assertSame(10.0, $booking->fresh()->tipsTotal());

        // …then it's refunded → the tip is taken back off.
        $reversed = $svc->recordTipFromWebhook(['data' => ['object' => ['refund' => [
            'id' => 'rf_1', 'payment_id' => 'pay_ref', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 1000],
        ]]]]);

        $this->assertNotNull($reversed);
        $this->assertSame(0.0, $booking->fresh()->tipsTotal());
    }

    public function test_a_payment_marked_refunded_also_reverses_the_tip(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create(['external_reference' => 'A078B2']);
        Http::fake(['*/v2/orders/*' => Http::response(['order' => ['reference_id' => 'TIP-A078B2']], 200)]);
        $svc = app(SquareTipService::class);

        $svc->recordTipFromWebhook(['data' => ['object' => ['payment' => [
            'id' => 'pay_r2', 'order_id' => 'order_1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 500],
        ]]]]);
        $this->assertSame(5.0, $booking->fresh()->tipsTotal());

        // payment.updated now shows the money fully refunded.
        $svc->recordTipFromWebhook(['data' => ['object' => ['payment' => [
            'id' => 'pay_r2', 'order_id' => 'order_1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => 500], 'refunded_money' => ['amount' => 500],
        ]]]]);
        $this->assertSame(0.0, $booking->fresh()->tipsTotal());
    }

    public function test_pending_payment_is_ignored(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create(['external_reference' => 'A078B2']);

        $result = app(SquareTipService::class)->recordTipFromWebhook([
            'data' => ['object' => ['payment' => [
                'id' => 'pay_x', 'order_id' => 'order_1', 'status' => 'PENDING',
                'amount_money' => ['amount' => 1000],
            ]]],
        ]);

        $this->assertNull($result);
        $this->assertSame(0.0, $booking->fresh()->tipsTotal());
    }

    public function test_review_message_never_carries_a_tip_link(): void
    {
        // Reviews get a clean, single ask — the tip link must NOT ride along,
        // even when Square is live (it hurts review conversion).
        $this->configureSquare();
        $booking = Booking::factory()->create(['driver_id' => User::factory()->driver()->create()->id]);

        $body = app(\App\Services\Messaging\BookingNotifier::class)->reviewBody($booking);

        $this->assertStringContainsString('Google:', $body);       // still asks for the review
        $this->assertStringNotContainsString('/tip/', $body);      // but no tip link
    }

    public function test_webhook_endpoint_rejects_a_bad_signature(): void
    {
        $this->configureSquare();

        $this->postJson(route('webhooks.square'), ['data' => []], ['x-square-hmacsha256-signature' => 'wrong'])
            ->assertStatus(403);
    }

    public function test_completing_a_job_creates_a_ready_to_send_tip_link_message(): void
    {
        $this->configureSquare();
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Majid Ali']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);

        app(\App\Services\BookingStatusService::class)
            ->forceTransition($booking, \App\Enums\BookingStatus::Complete, $admin, 'done');

        $tip = \App\Models\Message::where('booking_id', $booking->id)->where('type', 'tip_request')->first();
        $this->assertNotNull($tip);
        // Carries the tip link and is ready to send by hand right away.
        $this->assertStringContainsString($booking->fresh()->tipToken(), $tip->renderedBody());
        $this->assertTrue($tip->isReadyToSend());
        $this->assertNotNull($tip->whatsAppLink());
    }

    public function test_no_tip_message_is_created_when_card_tips_are_off(): void
    {
        // Square NOT configured.
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['driver_id' => User::factory()->driver()->create()->id]);

        app(\App\Services\BookingStatusService::class)
            ->forceTransition($booking, \App\Enums\BookingStatus::Complete, $admin, 'done');

        $this->assertFalse(\App\Models\Message::where('booking_id', $booking->id)->where('type', 'tip_request')->exists());
    }

    public function test_tip_page_amount_box_is_empty_and_offers_a_cash_option(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create();

        $this->get(route('tip.show', $booking->tipToken()))
            ->assertOk()
            ->assertDontSee('placeholder="25"', false)   // no pre-filled-looking amount
            ->assertSee('already given a cash tip');
    }

    public function test_customer_can_flag_a_cash_tip_without_paying(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create();

        $this->post(route('tip.cash', $booking->tipToken()))
            ->assertRedirect(route('tip.thanks', ['token' => $booking->tipToken(), 'cash' => 1]));

        $this->assertNotNull($booking->fresh()->customerCashTipNotedAt());
        // No card tip was recorded — we don't know the amount.
        $this->assertSame(0.0, $booking->fresh()->cardTipsOwed());
    }

    public function test_webhook_endpoint_accepts_a_correct_signature(): void
    {
        $this->configureSquare();
        $booking = Booking::factory()->create(['external_reference' => 'A078B2']);
        Http::fake(['*/v2/orders/*' => Http::response(['order' => ['reference_id' => 'TIP-A078B2']], 200)]);

        $payload = [
            'data' => ['object' => ['payment' => [
                'id' => 'pay_sig', 'order_id' => 'order_1', 'status' => 'COMPLETED',
                'amount_money' => ['amount' => 1500],
            ]]],
        ];
        $body = json_encode($payload);
        $url = route('webhooks.square');
        $signature = base64_encode(hash_hmac('sha256', $url.$body, 'sig-key', true));

        $this->call('POST', $url, [], [], [], [
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(15.0, $booking->fresh()->cardTipsOwed());
    }
}
