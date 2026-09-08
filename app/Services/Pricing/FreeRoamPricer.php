<?php

namespace App\Services\Pricing;

/**
 * CET's free-roam (non-fixed) fare, straight from the Price Guide:
 *   - First 10 miles: a flat minimum fare.
 *   - 11–100 miles: flat + (miles − 10) × tier-1 rate.
 *   - 100+ miles:   flat + 90 × tier-1 + (miles − 100) × tier-2 rate.
 *
 * The rate structure below is VAT-EXCLUSIVE (the raw Price Guide). After VAT
 * registration a flat £10 uplift is added to every quote to cover the VAT, and
 * the result is rounded to the nearest £5 so customers only ever see clean
 * figures (…£0 / …£5). One-way. Airport transfers use the fixed-price matrix
 * instead (QuoteService). Estate is always Executive + £10. Rolls Royce is POA.
 */
class FreeRoamPricer
{
    /** vehicle slug => [flat first-10mi, per-mile 11–100, per-mile 100+] — VAT-exclusive. */
    private const RATES = [
        'executive' => [50.00, 2.00, 1.73],
        'estate' => [50.00, 2.00, 1.73],       // derived as Executive + £10 (see price())
        'minibus-8' => [70.00, 2.23, 2.15],    // "8 Seater"
        'minibus-8-xl' => [90.00, 2.23, 2.15], // "8 Seater XL"
        'v-class' => [100.00, 2.73, 2.65],     // "Executive V Class" / Executive 8 Seater
    ];

    /**
     * The fare for a vehicle over a distance, VAT-inclusive and rounded to a clean
     * £5, or null when there's no rate (Rolls Royce = POA).
     */
    public function price(string $vehicleSlug, float $miles): ?float
    {
        // Estate is always Executive + £10, kept exact and still a clean figure.
        if ($vehicleSlug === 'estate') {
            $executive = $this->price('executive', $miles);

            return $executive === null
                ? null
                : $executive + (float) config('cet.estate_over_executive', 10);
        }

        $rate = self::RATES[$vehicleSlug] ?? null;
        if ($rate === null) {
            return null;
        }
        [$flat, $tier1, $tier2] = $rate;

        if ($miles <= 10) {
            $raw = $flat; // minimum fare
        } elseif ($miles <= 100) {
            $raw = $flat + ($miles - 10) * $tier1;
        } else {
            $raw = $flat + 90 * $tier1 + ($miles - 100) * $tier2;
        }

        // Add the flat VAT uplift, then round to the nearest £5 for a clean price.
        return $this->roundToFive($raw + (float) config('cet.freeroam_vat_uplift', 10));
    }

    /** Round to the nearest £5 so a fare always ends in £0 or £5 (never pennies). */
    private function roundToFive(float $amount): float
    {
        return round($amount / 5) * 5;
    }

    public function hasRate(string $vehicleSlug): bool
    {
        return $vehicleSlug === 'estate' || isset(self::RATES[$vehicleSlug]);
    }
}
