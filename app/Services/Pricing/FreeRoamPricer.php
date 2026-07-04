<?php

namespace App\Services\Pricing;

/**
 * CET's free-roam (non-fixed) fare, straight from the Price Guide:
 *   - First 10 miles: a flat minimum fare.
 *   - 11–100 miles: flat + (miles − 10) × tier-1 rate.
 *   - 100+ miles:   flat + 90 × tier-1 + (miles − 100) × tier-2 rate.
 * One-way. Airport transfers use the fixed-price matrix instead (see QuoteService).
 * Rolls Royce is price-on-request (no automatic rate).
 */
class FreeRoamPricer
{
    /** vehicle slug => [flat first-10mi, per-mile 11–100, per-mile 100+]. */
    private const RATES = [
        'executive' => [50.00, 2.00, 1.73],
        'estate' => [55.00, 2.00, 1.73],       // Executive + £5 (matches the fixed matrix)
        'minibus-8' => [70.00, 2.23, 2.15],    // "8 Seater"
        'minibus-8-xl' => [90.00, 2.23, 2.15], // "8 Seater XL"
        'v-class' => [100.00, 2.73, 2.65],     // "Executive V Class" / Executive 8 Seater
    ];

    /** The fare for a vehicle over a distance, or null when there's no rate (Rolls Royce = POA). */
    public function price(string $vehicleSlug, float $miles): ?float
    {
        $rate = self::RATES[$vehicleSlug] ?? null;
        if ($rate === null) {
            return null;
        }
        [$flat, $tier1, $tier2] = $rate;

        if ($miles <= 10) {
            return $flat; // minimum fare
        }
        if ($miles <= 100) {
            return round($flat + ($miles - 10) * $tier1, 2);
        }

        return round($flat + 90 * $tier1 + ($miles - 100) * $tier2, 2);
    }

    public function hasRate(string $vehicleSlug): bool
    {
        return isset(self::RATES[$vehicleSlug]);
    }
}
