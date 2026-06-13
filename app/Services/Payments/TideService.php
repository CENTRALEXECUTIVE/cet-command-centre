<?php

namespace App\Services\Payments;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates Tide hosted payment links for card bookings.
 *
 * Card details are NEVER handled or stored by this system — we only request a
 * hosted link from Tide and store the URL. When no API key is configured (local
 * / test) a deterministic placeholder link is returned so the flow still works.
 */
class TideService
{
    public function configured(): bool
    {
        return filled(config('services.tide.key'));
    }

    /**
     * Create (or return) a hosted payment link for a booking's outstanding amount.
     */
    public function createPaymentLink(Booking $booking, float $amount): string
    {
        if (! $this->configured()) {
            return $this->placeholderLink($booking, $amount);
        }

        try {
            $response = Http::withToken(config('services.tide.key'))
                ->acceptJson()
                ->post(rtrim(config('services.tide.base_url'), '/').'/v1/payment-links', [
                    'reference' => $booking->reference,
                    'amount' => (int) round($amount * 100), // pence
                    'currency' => 'GBP',
                    'description' => "CET booking {$booking->reference}",
                ]);

            if ($response->successful() && filled($response->json('url'))) {
                return $response->json('url');
            }

            Log::warning('Tide link creation failed', ['booking' => $booking->reference, 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::warning('Tide link exception', ['booking' => $booking->reference, 'error' => $e->getMessage()]);
        }

        // Never block a booking on a payment-link failure — fall back gracefully.
        return $this->placeholderLink($booking, $amount);
    }

    private function placeholderLink(Booking $booking, float $amount): string
    {
        $base = rtrim(config('services.tide.base_url'), '/');

        return "{$base}/cet/{$booking->reference}?amount=".number_format($amount, 2, '.', '');
    }
}
