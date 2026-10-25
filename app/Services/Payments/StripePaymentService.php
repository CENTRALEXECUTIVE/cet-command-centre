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

    /** reference_id prefix on a combined COVER-invoice session (ignored by the fare webhook). */
    public const COVER_PREFIX = 'COVER-';

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
     * A Stripe-hosted URL to pay this booking's fare, or null if unavailable.
     * $label sets the line-item name shown at checkout.
     *
     * Uses a durable Stripe PAYMENT LINK (not a Checkout Session) so the invoice
     * the office sends a customer does NOT expire after 24h — a Checkout Session
     * link went dead within a day, forcing the office to resend a fresh link,
     * which looked unprofessional. A Payment Link stays live until it's paid.
     * Falls back to a Checkout Session only if the payment link can't be made.
     */
    public function createCheckoutUrl(Booking $booking, float $amount, ?string $redirectUrl = null, ?string $label = null): ?string
    {
        if (! $this->enabled() || $amount <= 0) {
            return null;
        }

        $reference = (string) ($booking->external_reference ?: $booking->reference);
        // Stripe is the NON-VAT provider → bill under the sister company, PVT LTD.
        $name = ($label ? trim($label) : 'Journey fare').' — '.config('cet.company_novat.name', 'Central Executive Transfers PVT LTD').' ('.$booking->reference.')';

        $link = $this->createPaymentLink($name, $amount, self::FARE_PREFIX.$reference, $redirectUrl, [
            'reference' => $reference,
            'cet_booking' => (string) $booking->id,
        ]);

        return $link ?: $this->createCheckoutSession($name, $amount, self::FARE_PREFIX.$reference, $redirectUrl, [
            'reference' => $reference,
            'cet_booking' => (string) $booking->id,
        ]);
    }

    /**
     * Create a durable, non-expiring Stripe Payment Link for a one-off amount and
     * return its URL. Payment Links require a Price (not inline price_data), so we
     * first mint an inline product+price, then build a single-use link that carries
     * our reference back on the resulting checkout.session.completed webhook via the
     * ?client_reference_id query param. Null on any failure, so the caller can fall
     * back to a Checkout Session.
     */
    private function createPaymentLink(string $name, float $amount, string $clientRef, ?string $redirectUrl, array $metadata = []): ?string
    {
        try {
            // 1) Inline product + price in one call.
            $price = $this->http()->post(self::API.'/prices', [
                'currency' => 'gbp',
                'unit_amount' => (string) ((int) round($amount * 100)),
                'product_data[name]' => $name,
            ]);
            if ($price->failed() || ! $price->json('id')) {
                Log::warning('[Stripe] price create failed', ['status' => $price->status(), 'body' => $price->body()]);

                return null;
            }

            // 2) Single-use payment link that never expires.
            $params = [
                'line_items[0][price]' => (string) $price->json('id'),
                'line_items[0][quantity]' => '1',
                'restrictions[completed_sessions][limit]' => '1',
            ];
            foreach ($metadata as $k => $v) {
                $params['metadata['.$k.']'] = (string) $v;
            }
            if ($redirectUrl) {
                $params['after_completion[type]'] = 'redirect';
                $params['after_completion[redirect][url]'] = $redirectUrl;
            }

            $res = $this->http()->post(self::API.'/payment_links', array_filter($params, fn ($v) => $v !== null && $v !== ''));
            if ($res->failed() || ! $res->json('url')) {
                Log::warning('[Stripe] payment link failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            // The checkout.session.completed event copies client_reference_id from
            // this query param, so the fare webhook can match the booking.
            $url = (string) $res->json('url');

            return $url.(str_contains($url, '?') ? '&' : '?').'client_reference_id='.rawurlencode($clientRef);
        } catch (\Throwable $e) {
            Log::warning('[Stripe] payment link error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Fallback: a one-shot Stripe Checkout Session (expires within 24h). Used only
     * when a durable payment link can't be created.
     */
    private function createCheckoutSession(string $name, float $amount, string $clientRef, ?string $redirectUrl, array $metadata = []): ?string
    {
        try {
            $params = [
                'mode' => 'payment',
                'success_url' => $redirectUrl ?: config('app.url'),
                'cancel_url' => $redirectUrl ?: config('app.url'),
                'client_reference_id' => $clientRef,
                'line_items[0][quantity]' => '1',
                'line_items[0][price_data][currency]' => 'gbp',
                'line_items[0][price_data][unit_amount]' => (string) ((int) round($amount * 100)),
                'line_items[0][price_data][product_data][name]' => $name,
            ];
            foreach ($metadata as $k => $v) {
                $params['metadata['.$k.']'] = (string) $v;
            }

            $res = $this->http()->post(self::API.'/checkout/sessions', array_filter($params, fn ($v) => $v !== null && $v !== ''));
            if ($res->failed()) {
                Log::warning('[Stripe] checkout session failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            return $res->json('url');
        } catch (\Throwable $e) {
            Log::warning('[Stripe] checkout session error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * A Stripe-hosted Checkout URL to pay a COMBINED COVER INVOICE — one operator,
     * several jobs, one total — billed by the non-VAT sister company (PVT LTD).
     * NOT tied to any booking's fare: the client_reference_id carries the COVER-
     * prefix and no fare metadata, so recordFareFromWebhook() deliberately ignores
     * it and never marks a booking paid (the office reconciles cover payments in
     * Stripe). Null if Stripe isn't connected. Mirrors the Square cover link so a
     * non-VAT cover invoice can be paid the same way, through Stripe.
     */
    public function createCoverCheckoutUrl(float $amount, string $reference, string $label, ?string $redirectUrl = null): ?string
    {
        if (! $this->enabled() || $amount <= 0) {
            return null;
        }

        // Stripe is the NON-VAT provider → bill under the sister company,
        // Central Executive Transfers PVT LTD, not the VAT company.
        $name = trim($label).' — '.config('cet.company_novat.name', 'Central Executive Transfers PVT LTD').' ('.$reference.')';

        // Durable payment link so a cover invoice sent to an operator doesn't die
        // after 24h; Checkout Session only as a fallback.
        return $this->createPaymentLink($name, $amount, self::COVER_PREFIX.$reference, $redirectUrl)
            ?: $this->createCheckoutSession($name, $amount, self::COVER_PREFIX.$reference, $redirectUrl);
    }

    /**
     * A throwaway test Checkout to prove the Stripe flow works end to end, WITHOUT
     * touching any booking: the client_reference_id carries a TEST- prefix and no
     * fare metadata, so the webhook ignores it and nothing is marked paid. Refund
     * the charge in Stripe. Null if unavailable.
     */
    public function createTestCheckoutUrl(float $amount, ?string $redirectUrl = null): ?string
    {
        if (! $this->enabled() || $amount <= 0) {
            return null;
        }

        try {
            $res = $this->http()->post(self::API.'/checkout/sessions', array_filter([
                'mode' => 'payment',
                'success_url' => $redirectUrl ?: config('app.url'),
                'cancel_url' => $redirectUrl ?: config('app.url'),
                'client_reference_id' => 'TEST-'.strtoupper(Str::random(8)),
                'line_items[0][quantity]' => '1',
                'line_items[0][price_data][currency]' => 'gbp',
                'line_items[0][price_data][unit_amount]' => (string) ((int) round($amount * 100)),
                'line_items[0][price_data][product_data][name]' => 'TEST PAYMENT — Central Executive Transfers (safe to refund)',
            ], fn ($v) => $v !== null && $v !== ''));

            return $res->failed() ? null : $res->json('url');
        } catch (\Throwable $e) {
            Log::warning('[Stripe] test link error: '.$e->getMessage());

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
     * If this completed Stripe webhook is a COVER- combined-invoice payment, return
     * the stripped cover reference (so the office's cover jobs can be marked paid).
     * Null for anything else — a fare (FARE-), a test, or an unpaid session.
     */
    public function coverReferenceFromWebhook(array $payload): ?string
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
        $ref = (string) ($session['client_reference_id'] ?? '');

        return str_starts_with($ref, self::COVER_PREFIX) ? substr($ref, strlen(self::COVER_PREFIX)) : null;
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
