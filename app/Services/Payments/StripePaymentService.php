<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Card payment for the SISTER company — Central Executive Transfers PVT LTD (the
 * NON-VAT entity) — via Stripe Checkout. A customer who does not ask for a VAT
 * invoice is billed by PVT LTD and pays through this account; VAT customers go to
 * Central Executive Transfers Ltd via Square instead.
 *
 * Deliberately NO Stripe SDK — raw HTTP, so a deploy needs no composer step
 * (mirrors the Square service). Keys set in Settings (DB) override the .env
 * defaults. Silent no-op until the secret key is set, so nothing breaks before
 * go-live.
 */
class StripePaymentService
{
    private const API = 'https://api.stripe.com/v1';

    /** reference_id prefix on a fare Checkout Session (vs COVER-/others). */
    public const FARE_PREFIX = 'FARE-';

    private function secretKey(): ?string
    {
        return Setting::get('stripe_secret_key') ?: config('services.stripe.secret_key');
    }

    private function webhookSecret(): ?string
    {
        return Setting::get('stripe_webhook_secret') ?: config('services.stripe.webhook_secret');
    }

    public function enabled(): bool
    {
        return filled($this->secretKey());
    }

    private function http()
    {
        return Http::asForm()
            ->withToken((string) $this->secretKey())
            ->timeout(15);
    }

    /**
     * A Stripe-hosted Checkout URL to pay this booking's fare, or null if
     * unavailable. $label sets the line-item name shown at checkout.
     */
    public function createCheckoutUrl(Booking $booking, float $amount, ?string $redirectUrl = null, ?string $label = null): ?string
    {
        if (! $this->enabled() || $amount <= 0) {
            return null;
        }

        $reference = (string) ($booking->external_reference ?: $booking->reference);
        $name = ($label ? trim($label) : 'Journey fare').' — Central Executive Transfers ('.$booking->reference.')';

        try {
            $res = $this->http()->post(self::API.'/checkout/sessions', array_filter([
                'mode' => 'payment',
                'success_url' => $redirectUrl ?: config('app.url'),
                'cancel_url' => $redirectUrl ?: config('app.url'),
                'client_reference_id' => self::FARE_PREFIX.$reference,
                'metadata[reference]' => $reference,
                'metadata[cet_booking]' => (string) $booking->id,
                'line_items[0][quantity]' => '1',
                'line_items[0][price_data][currency]' => 'gbp',
                'line_items[0][price_data][unit_amount]' => (string) ((int) round($amount * 100)),
                'line_items[0][price_data][product_data][name]' => $name,
            ], fn ($v) => $v !== null && $v !== ''));

            if ($res->failed()) {
                Log::warning('[Stripe] checkout link failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            return $res->json('url');
        } catch (\Throwable $e) {
            Log::warning('[Stripe] checkout link error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Mark a booking's fare paid from a Stripe webhook. Handles the
     * checkout.session.completed event: reads our reference off the session and
     * records the payment. Returns the booking, or null if it's not one of ours.
     */
    public function recordFareFromWebhook(array $payload): ?Booking
    {
        if (($payload['type'] ?? null) !== 'checkout.session.completed') {
            return null;
        }

        $session = data_get($payload, 'data.object');
        if (! is_array($session)) {
            return null;
        }
        if (strtolower((string) ($session['payment_status'] ?? '')) !== 'paid') {
            return null;
        }

        $clientRef = (string) ($session['client_reference_id'] ?? '');
        $reference = (string) data_get($session, 'metadata.reference', '');
        if ($clientRef !== '' && str_starts_with($clientRef, self::FARE_PREFIX)) {
            $reference = substr($clientRef, strlen(self::FARE_PREFIX));
        }
        if ($reference === '') {
            return null;
        }

        $paymentId = (string) ($session['payment_intent'] ?? $session['id'] ?? '');
        $amount = (int) ($session['amount_total'] ?? 0);
        if ($paymentId === '' || $amount <= 0) {
            return null;
        }

        $booking = Booking::where('external_reference', $reference)->orWhere('reference', $reference)->first();
        if (! $booking) {
            Log::warning('[Stripe] fare for unknown reference', ['reference' => $reference, 'payment' => $paymentId]);

            return null;
        }

        if (! $booking->markFarePaid($paymentId, $amount / 100, 'chauffeurs')) {
            return null;
        }

        return $booking;
    }

    /**
     * Verify a Stripe webhook signature (the `Stripe-Signature` header) against
     * the signing secret, raw body and a tolerance window. Standard Stripe scheme:
     * sign "{t}.{payload}" with HMAC-SHA256, compare the v1 signature.
     */
    public function verifyWebhook(string $payload, ?string $sigHeader, int $toleranceSeconds = 300): bool
    {
        $secret = $this->webhookSecret();
        if (blank($secret) || blank($sigHeader)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, (string) $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    /** Make a short idempotency key for request retries (unused for GETs). */
    private function idem(): string
    {
        return (string) Str::uuid();
    }
}
