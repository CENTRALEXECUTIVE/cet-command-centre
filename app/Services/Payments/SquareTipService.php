<?php

namespace App\Services\Payments;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Customer card TIPS for the driver, via Square-hosted checkout.
 *
 * Deliberately no SDK — plain HTTP through Laravel's Http client, so deploys
 * need no composer step (same choice as the Twilio masking service). The whole
 * thing is a silent no-op until SQUARE_ACCESS_TOKEN + SQUARE_LOCATION_ID are set.
 *
 * Flow: the customer opens their tip link, picks an amount, and we create a
 * Square payment link (an order tagged with the booking reference). They pay on
 * Square's hosted page. Square's `payment.created/updated` webhook then calls
 * recordTipFromWebhook(), which finds the booking by the order reference and
 * logs the tip onto the driver's payroll — idempotent by Square payment id.
 */
class SquareTipService
{
    private const VERSION = '2024-10-17';

    /** Live only when the token + location are configured. */
    public function enabled(): bool
    {
        return filled(config('services.square.access_token'))
            && filled(config('services.square.location_id'));
    }

    /** Preset tip buttons (£) shown to the customer; "Other" is always offered too. */
    public function presetAmounts(): array
    {
        return array_values(array_filter(
            (array) config('services.square.tip_amounts', [5, 10, 20]),
            fn ($n) => (float) $n > 0
        ));
    }

    private function baseUrl(): string
    {
        return config('services.square.environment') === 'sandbox'
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }

    private function http()
    {
        return Http::withToken(config('services.square.access_token'))
            ->withHeaders(['Square-Version' => self::VERSION])
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Create a Square-hosted checkout link for a tip of £{amount} on this booking
     * and return its URL, or null if Square isn't configured or the call failed.
     * The order carries the booking reference so the webhook can attribute it.
     */
    public function createCheckoutUrl(Booking $booking, float $amount, ?string $redirectUrl = null): ?string
    {
        if (! $this->enabled() || $amount <= 0) {
            return null;
        }

        $driver = $booking->driverPublicName() ?: 'your driver';

        try {
            $res = $this->http()->post($this->baseUrl().'/v2/online-checkout/payment-links', [
                'idempotency_key' => (string) Str::uuid(),
                'order' => [
                    'location_id' => config('services.square.location_id'),
                    'reference_id' => $this->reference($booking),
                    'line_items' => [[
                        'name' => 'Tip for '.$driver.' — Central Executive Transfers',
                        'quantity' => '1',
                        'base_price_money' => [
                            'amount' => (int) round($amount * 100), // pennies
                            'currency' => 'GBP',
                        ],
                    ]],
                ],
                'checkout_options' => array_filter([
                    'redirect_url' => $redirectUrl,
                    'ask_for_shipping_address' => false,
                ]),
            ]);

            if ($res->failed()) {
                Log::warning('[Square] payment link failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            return $res->json('payment_link.url');
        } catch (\Throwable $e) {
            Log::warning('[Square] payment link error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Handle a Square webhook payload: if it's a completed payment, attribute it
     * to its booking and log the tip. Returns the booking it was logged against,
     * or null when nothing was recorded (not a payment, not completed, no match,
     * or already logged).
     */
    public function recordTipFromWebhook(array $payload): ?Booking
    {
        // A refund event, or a payment reported as refunded → reverse the tip.
        if ($refund = data_get($payload, 'data.object.refund')) {
            return strtoupper((string) ($refund['status'] ?? '')) === 'COMPLETED'
                ? $this->reverseTip((string) ($refund['payment_id'] ?? ''))
                : null;
        }

        $payment = data_get($payload, 'data.object.payment');
        if (! is_array($payment)) {
            return null;
        }

        $paymentId = (string) ($payment['id'] ?? '');
        $amount = (int) data_get($payment, 'amount_money.amount', 0);
        $refunded = (int) data_get($payment, 'refunded_money.amount', 0);

        // The tip was refunded (fully) → take it back off the driver's payroll.
        if ($paymentId !== '' && $refunded > 0 && $refunded >= $amount) {
            return $this->reverseTip($paymentId);
        }

        // Only count money actually taken.
        if (! in_array(strtoupper((string) ($payment['status'] ?? '')), ['COMPLETED', 'APPROVED'], true)) {
            return null;
        }

        $orderId = (string) ($payment['order_id'] ?? '');
        if ($paymentId === '' || $orderId === '' || $amount <= 0) {
            return null;
        }

        $reference = $this->retrieveOrderReference($orderId);
        if (! $reference) {
            return null;
        }

        $booking = $this->bookingForReference($reference);
        if (! $booking) {
            Log::warning('[Square] tip for unknown reference', ['reference' => $reference, 'payment' => $paymentId]);

            return null;
        }

        if (! $booking->logSquareTip($amount / 100, $paymentId)) {
            return null; // already logged (idempotent)
        }

        // Delight the driver — buzz their phone that they've been tipped.
        $this->pingDriver($booking, $amount / 100);

        return $booking;
    }

    /** Remove the tip tied to a Square payment (refund). Returns its booking. */
    private function reverseTip(string $paymentId): ?Booking
    {
        if ($paymentId === '') {
            return null;
        }
        $tip = \App\Models\BookingTip::where('square_payment_id', $paymentId)->first();
        if (! $tip) {
            return null;
        }

        $booking = $tip->booking;
        $tip->delete();
        Log::info('[Square] tip reversed by refund', ['payment' => $paymentId, 'booking' => $booking?->id]);

        return $booking;
    }

    /** Push a "you've been tipped" notification to the job's driver, if any. */
    private function pingDriver(Booking $booking, float $amount): void
    {
        $driver = $booking->driver;
        if (! $driver) {
            return;
        }

        try {
            app(\App\Services\Push\WebPushService::class)->sendToUser(
                $driver,
                '💛 You got a tip!',
                '£'.number_format($amount, 2).' from '.($booking->displayName() ?: 'a customer').' — thank you!',
                ['tag' => 'tip-'.$booking->id],
            );
        } catch (\Throwable $e) {
            Log::warning('[Square] tip push failed: '.$e->getMessage());
        }
    }

    /**
     * Verify a Square webhook signature: base64(HMAC-SHA256(key, notificationUrl + body)).
     * Fails closed when no signing key is configured.
     */
    public function verifySignature(?string $signature, string $body, string $notificationUrl): bool
    {
        $key = config('services.square.webhook_signature_key');
        if (blank($key) || blank($signature)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));

        return hash_equals($expected, $signature);
    }

    /** GET the order and read its reference_id (the booking reference). */
    private function retrieveOrderReference(string $orderId): ?string
    {
        try {
            $res = $this->http()->get($this->baseUrl().'/v2/orders/'.$orderId);

            return $res->successful() ? ($res->json('order.reference_id') ?: null) : null;
        } catch (\Throwable $e) {
            Log::warning('[Square] order lookup error: '.$e->getMessage());

            return null;
        }
    }

    /** The reference we stamp on the order — the booking's external ref, else its own. */
    private function reference(Booking $booking): string
    {
        return (string) ($booking->external_reference ?: $booking->reference);
    }

    private function bookingForReference(string $reference): ?Booking
    {
        return Booking::where('external_reference', $reference)
            ->orWhere('reference', $reference)
            ->first();
    }
}
