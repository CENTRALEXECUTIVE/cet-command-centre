<?php

namespace App\Services\Payments;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Card payment for a booking FARE via Square-hosted checkout — the customer pays
 * for their journey (from the web widget). Same deliberate no-SDK HTTP approach
 * as the tip service, and a silent no-op until Square is configured.
 *
 * Orders are stamped with a FARE- prefix so the webhook can tell a fare payment
 * apart from a tip (TIP-) and mark the booking paid. Idempotent by Square
 * payment id (stored in booking.meta), so a repeated webhook never double-marks.
 */
class SquareBookingPaymentService
{
    private const VERSION = '2024-10-17';

    /** Prefix stamped on a fare order's reference_id (vs TIP- for gratuities). */
    public const FARE_PREFIX = 'FARE-';

    /** Prefix for a combined cover-invoice payment link (operator pays CET). */
    public const COVER_PREFIX = 'COVER-';

    /**
     * The Square credentials for a billing entity: 'transfers' (main, VAT) or
     * 'chauffeurs' (sister company, no-VAT). The sister account falls back to the
     * main one until it's configured, so nothing breaks before it exists.
     *
     * @return array{access_token:?string, location_id:?string, environment:?string, webhook_signature_key:?string}
     */
    private function account(string $entity = 'transfers'): array
    {
        // In-app Settings win over .env, so keys can be pasted in the admin without a
        // deploy (mirrors the Google Maps key pattern).
        $setting = fn (string $key, string $configKey) => \App\Models\Setting::get($key) ?: config($configKey);

        $main = [
            'access_token' => $setting('square_access_token', 'services.square.access_token'),
            'location_id' => $setting('square_location_id', 'services.square.location_id'),
            'app_id' => $setting('square_app_id', 'services.square.app_id'),
            'environment' => $setting('square_environment', 'services.square.environment') ?: 'production',
            'webhook_signature_key' => $setting('square_webhook_signature_key', 'services.square.webhook_signature_key'),
        ];
        if ($entity !== 'chauffeurs') {
            return $main;
        }

        $sister = [
            'access_token' => $setting('square_chauffeurs_access_token', 'services.square_chauffeurs.access_token'),
            'location_id' => $setting('square_chauffeurs_location_id', 'services.square_chauffeurs.location_id'),
            'app_id' => $setting('square_chauffeurs_app_id', 'services.square_chauffeurs.app_id'),
            'environment' => $setting('square_chauffeurs_environment', 'services.square_chauffeurs.environment') ?: $main['environment'],
            'webhook_signature_key' => $setting('square_chauffeurs_webhook_signature_key', 'services.square_chauffeurs.webhook_signature_key'),
        ];

        return (filled($sister['access_token']) && filled($sister['location_id']))
            ? array_merge($main, array_filter($sister, fn ($v) => $v !== null && $v !== ''))
            : $main;
    }

    public function enabled(string $entity = 'transfers'): bool
    {
        $a = $this->account($entity);

        return filled($a['access_token'] ?? null) && filled($a['location_id'] ?? null);
    }

    private function baseUrl(string $entity = 'transfers'): string
    {
        return ($this->account($entity)['environment'] ?? null) === 'sandbox'
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }

    private function http(string $entity = 'transfers')
    {
        return Http::withToken($this->account($entity)['access_token'] ?? '')
            ->withHeaders(['Square-Version' => self::VERSION])
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * A Square-hosted checkout URL to pay this booking's fare, or null if
     * unavailable. $label sets the line-item name shown on the checkout (e.g.
     * "VAT balance payment"); defaults to the journey fare.
     */
    /**
     * A Square-hosted checkout URL to pay a COMBINED COVER INVOICE — one operator,
     * several jobs, one total. Not tied to a booking's fare: the reference carries
     * the COVER- prefix, so the fare webhook deliberately ignores it and never
     * marks a booking paid off this link (the office reconciles cover payments in
     * Square). Billed to the main 'transfers' Square account. Null if unavailable.
     */
    public function createCoverCheckoutUrl(float $amount, string $reference, string $label, ?string $redirectUrl = null, string $entity = 'transfers'): ?string
    {
        if (! $this->enabled($entity) || $amount <= 0) {
            return null;
        }

        try {
            $res = $this->http($entity)->post($this->baseUrl($entity).'/v2/online-checkout/payment-links', [
                'idempotency_key' => (string) Str::uuid(),
                'order' => [
                    'location_id' => $this->account($entity)['location_id'],
                    'reference_id' => self::COVER_PREFIX.$reference,
                    'line_items' => [[
                        'name' => trim($label).' — Central Executive Transfers ('.$reference.')',
                        'quantity' => '1',
                        'base_price_money' => ['amount' => (int) round($amount * 100), 'currency' => 'GBP'],
                    ]],
                ],
                'checkout_options' => array_filter([
                    'redirect_url' => $redirectUrl,
                    'ask_for_shipping_address' => false,
                ]),
            ]);

            if ($res->failed()) {
                Log::warning('[Square] cover link failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            return $res->json('payment_link.url');
        } catch (\Throwable $e) {
            Log::warning('[Square] cover link error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * A throwaway £-value test checkout to prove the card flow works end to end,
     * WITHOUT touching any booking: the reference carries a TEST- prefix, so the
     * fare webhook ignores it and no booking is ever marked paid. The office can
     * refund the charge in Square. Null if unavailable.
     */
    public function createTestCheckoutUrl(string $entity, float $amount, ?string $redirectUrl = null): ?string
    {
        if (! $this->enabled($entity) || $amount <= 0) {
            return null;
        }

        try {
            $res = $this->http($entity)->post($this->baseUrl($entity).'/v2/online-checkout/payment-links', [
                'idempotency_key' => (string) Str::uuid(),
                'order' => [
                    'location_id' => $this->account($entity)['location_id'],
                    'reference_id' => 'TEST-'.strtoupper(Str::random(8)),
                    'line_items' => [[
                        'name' => 'TEST PAYMENT — Central Executive Transfers (safe to refund)',
                        'quantity' => '1',
                        'base_price_money' => ['amount' => (int) round($amount * 100), 'currency' => 'GBP'],
                    ]],
                ],
                'checkout_options' => array_filter(['redirect_url' => $redirectUrl, 'ask_for_shipping_address' => false]),
            ]);

            return $res->failed() ? null : $res->json('payment_link.url');
        } catch (\Throwable $e) {
            Log::warning('[Square] test link error: '.$e->getMessage());

            return null;
        }
    }

    public function createCheckoutUrl(Booking $booking, float $amount, ?string $redirectUrl = null, ?string $label = null): ?string
    {
        // Route the payment to the right company's Square account (VAT-invoice
        // customers → transfers; everyone else → the sister company chauffeurs).
        $entity = $booking->billingEntity();
        if (! $this->enabled($entity) || $amount <= 0) {
            return null;
        }

        try {
            $res = $this->http($entity)->post($this->baseUrl($entity).'/v2/online-checkout/payment-links', [
                'idempotency_key' => (string) Str::uuid(),
                'order' => [
                    'location_id' => $this->account($entity)['location_id'],
                    'reference_id' => self::FARE_PREFIX.$this->reference($booking),
                    'line_items' => [[
                        'name' => ($label ? trim($label) : 'Journey fare').' — Central Executive Transfers ('.$booking->reference.')',
                        'quantity' => '1',
                        'base_price_money' => ['amount' => (int) round($amount * 100), 'currency' => 'GBP'],
                    ]],
                ],
                'checkout_options' => array_filter([
                    'redirect_url' => $redirectUrl,
                    'ask_for_shipping_address' => false,
                ]),
            ]);

            if ($res->failed()) {
                Log::warning('[Square] fare link failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            return $res->json('payment_link.url');
        } catch (\Throwable $e) {
            Log::warning('[Square] fare link error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Handle a Square webhook: mark the booking PAID when a FARE- order is
     * completed. Returns the booking it marked, or null (not a fare, not
     * completed, no match, or already recorded).
     */
    public function recordFareFromWebhook(array $payload, string $entity = 'transfers'): ?Booking
    {
        $payment = data_get($payload, 'data.object.payment');
        if (! is_array($payment)) {
            return null;
        }

        $paymentId = (string) ($payment['id'] ?? '');
        $amount = (int) data_get($payment, 'amount_money.amount', 0);
        $orderId = (string) ($payment['order_id'] ?? '');

        if (! in_array(strtoupper((string) ($payment['status'] ?? '')), ['COMPLETED', 'APPROVED'], true)) {
            return null;
        }
        if ($paymentId === '' || $orderId === '' || $amount <= 0) {
            return null;
        }

        // Look the order up on the SAME account the webhook came from.
        $reference = $this->retrieveOrderReference($orderId, $entity);
        if (! $reference || ! str_starts_with($reference, self::FARE_PREFIX)) {
            return null; // not one of our fare checkouts (a tip, or another charge)
        }
        $reference = substr($reference, strlen(self::FARE_PREFIX));

        $booking = $this->bookingForReference($reference);
        if (! $booking) {
            Log::warning('[Square] fare for unknown reference', ['reference' => $reference, 'payment' => $paymentId]);

            return null;
        }

        if (! $booking->markFarePaid($paymentId, $amount / 100, $entity)) {
            return null;
        }

        // If this is the VAT top-up on a VAT invoice (the payment covers the VAT
        // added on top), mark the VAT received so the invoice reads paid in full.
        $vatOnTop = $booking->vatOnTopAmount();
        if ($vatOnTop > 0 && ($amount / 100) + 0.01 >= $vatOnTop) {
            $booking->markVatReceived(true);
        }

        return $booking;
    }

    private function retrieveOrderReference(string $orderId, string $entity = 'transfers'): ?string
    {
        try {
            $res = $this->http($entity)->get($this->baseUrl($entity).'/v2/orders/'.$orderId);

            return $res->successful() ? ($res->json('order.reference_id') ?: null) : null;
        } catch (\Throwable $e) {
            Log::warning('[Square] order lookup error: '.$e->getMessage());

            return null;
        }
    }

    /** The webhook signing key for an entity, for signature verification. */
    public function webhookSignatureKey(string $entity = 'transfers'): ?string
    {
        return $this->account($entity)['webhook_signature_key'] ?? null;
    }

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
