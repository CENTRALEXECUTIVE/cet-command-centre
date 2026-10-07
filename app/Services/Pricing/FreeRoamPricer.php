<?php

namespace App\Services\Pricing;

use App\Models\Setting;

/**
 * CET's free-roam (non-fixed) fare, straight from the Price Guide:
 *   - First 10 miles: a flat minimum fare.
 *   - 11–100 miles: flat + (miles − 10) × tier-1 rate.
 *   - 100+ miles:   flat + 90 × tier-1 + (miles − 100) × tier-2 rate.
 *
 * The rate structure below, and every price this returns, is VAT-EXCLUSIVE —
 * the standard price the customer pays. VAT (20%) is NOT baked in; it is only
 * ever added on top when a VAT invoice is requested, and the office is shown both
 * figures (QuoteService returns the ex-VAT price and the with-VAT price). Prices
 * are rounded to the nearest £5 so they're always clean (…£0 / …£5). One-way.
 * Airport transfers use the fixed-price matrix instead (QuoteService). Estate is
 * always Executive + £10. Rolls Royce is POA.
 *
 * Rates and the estate uplift are office-editable from the Free-roam rates admin
 * (stored in Settings), falling back to these defaults so pricing never breaks if
 * nothing has been saved.
 */
class FreeRoamPricer
{
    /** vehicle slug => [flat first-10mi, per-mile 11–100, per-mile 100+] — VAT-exclusive. */
    public const DEFAULT_RATES = [
        'executive' => [50.00, 2.00, 1.73],
        'estate' => [50.00, 2.00, 1.73],       // derived as Executive + £10 (see price())
        'minibus-8' => [70.00, 2.23, 2.15],    // "8 Seater"
        'minibus-8-xl' => [90.00, 2.23, 2.15], // "8 Seater XL"
        'v-class' => [100.00, 2.73, 2.65],     // "Executive V Class" / Executive 8 Seater
    ];

    /**
     * The live rate table: the office-saved override merged over the defaults, so
     * a partial save (one vehicle) leaves the rest at their defaults.
     *
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public function rates(): array
    {
        $saved = (array) Setting::get('freeroam_rates', []);
        $rates = self::DEFAULT_RATES;

        foreach ($saved as $slug => $row) {
            if (is_array($row) && count($row) === 3) {
                $rates[$slug] = [(float) $row[0], (float) $row[1], (float) $row[2]];
            }
        }

        return $rates;
    }

    /** How much more an Estate is than an Executive (office-editable). */
    public function estateUplift(): float
    {
        $v = Setting::get('freeroam_estate_uplift');

        return $v === null ? (float) config('cet.estate_over_executive', 10) : (float) $v;
    }

    /** The out-of-area dead-mileage rate per empty mile (office-editable). */
    public function deadMileageRate(): float
    {
        $v = Setting::get('deadmile_rate');

        return $v === null ? (float) config('cet.dead_mileage.rate_per_mile', 1.00) : (float) $v;
    }

    /** Pickups within this many miles of base pay NO dead mileage (office-editable). */
    public function deadMileageFreeRadius(): float
    {
        $v = Setting::get('deadmile_radius');

        return $v === null ? (float) config('cet.dead_mileage.free_radius_miles', 15.0) : (float) $v;
    }

    /**
     * The dead-mileage charge for an empty run of $baseToPickupMiles out to the
     * pickup. Nothing inside the free radius; beyond it, the WHOLE distance is
     * billed at the per-mile rate (so a 40-mile-out job charges 40 × rate), then
     * rounded to a clean £5. Returns 0.0 when it doesn't apply.
     */
    public function deadMileageCharge(?float $baseToPickupMiles): float
    {
        if ($baseToPickupMiles === null || $baseToPickupMiles <= $this->deadMileageFreeRadius()) {
            return 0.0;
        }

        return $this->roundToFive($baseToPickupMiles * $this->deadMileageRate());
    }

    /**
     * The STANDARD (VAT-EXCLUSIVE) fare for a vehicle over a distance, rounded to a
     * clean £5, or null when there's no rate (Rolls Royce = POA). VAT is added
     * separately only when requested — see QuoteService / priceWithVat().
     */
    public function price(string $vehicleSlug, float $miles): ?float
    {
        // Estate is always Executive + the estate uplift, kept exact and clean.
        if ($vehicleSlug === 'estate') {
            $executive = $this->price('executive', $miles);

            return $executive === null ? null : $executive + $this->estateUplift();
        }

        $rate = $this->rates()[$vehicleSlug] ?? null;
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

        // Round to the nearest £5 for a clean ex-VAT price.
        return $this->roundToFive($raw);
    }

    /**
     * The same fare WITH VAT added on top (for when a VAT invoice is requested).
     * Uses the single VAT service so it agrees with receipts/invoices. Null when
     * there's no automatic rate.
     */
    public function priceWithVat(string $vehicleSlug, float $miles): ?float
    {
        $net = $this->price($vehicleSlug, $miles);

        return $net === null ? null : app(\App\Services\Payments\VatService::class)->fromNet($net)['gross'];
    }

    /** Round to the nearest £5 so a fare always ends in £0 or £5 (never pennies). */
    private function roundToFive(float $amount): float
    {
        return round($amount / 5) * 5;
    }

    public function hasRate(string $vehicleSlug): bool
    {
        return $vehicleSlug === 'estate' || isset($this->rates()[$vehicleSlug]);
    }
}
