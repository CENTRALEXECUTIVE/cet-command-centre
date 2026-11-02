<?php

namespace App\Services\Pricing;

use App\Models\VehicleType;
use App\Services\Payments\VatService;
use Illuminate\Support\Carbon;

/**
 * Builds the FULL customer fare for the public booking system, layering the CET
 * pricing the same way ETO did:
 *
 *   base fare (fixed matrix / free-roam, QuoteService)
 *     × holiday-or-rush factor (config: Christmas ×1.3, New Year ×1.5, …)
 *     + itemised extras (meet & greet, seats, extra stops, ribbons, hourly hire)
 *     − voucher discount (applied by the caller once validated)
 *
 * Every figure is VAT-inclusive for the public. Returns null base for a
 * price-on-request vehicle (e.g. Luxury), so the caller shows an enquiry instead.
 */
class FareCalculator
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly VatService $vat,
    ) {}

    /**
     * @param  array<string, int|bool>  $options  extra quantities/flags (see extraItems)
     * @return array{
     *   base: float|null, poa: bool, fixed: bool,
     *   surcharge: array{label: string, factor: float, amount: float}|null,
     *   extras: list<array{label: string, qty: int, unit: float, amount: float}>,
     *   extras_total: float, subtotal: float|null
     * }
     */
    public function calculate(string $pickup, string $destination, VehicleType $vehicleType, Carbon $pickupAt, array $options = []): array
    {
        // The pickup time goes INTO the quote, so the festive table and the office
        // night/date rules are applied once, by the same rule the customer widget uses
        // (QuoteService → TimeSurcharges::forStandardFare), on the standard fare only.
        $quote = $this->quotes->quote($pickup, $destination, $vehicleType, $pickupAt);
        $amount = (float) ($quote['surcharge'] ?? 0);
        $baseWithSurcharge = $quote['price'];
        $base = $baseWithSurcharge === null ? null : round((float) $baseWithSurcharge - $amount, 2);

        $surcharge = ($base !== null && $amount > 0)
            ? ['label' => (string) $quote['surcharge_label'], 'factor' => $quote['surcharge_factor'] ?? null, 'amount' => $amount]
            : null;

        // At an airport hotel / private-jet handler the meet & greet fee is still
        // charged, but it's called an airport pickup (no name board in arrivals).
        $options['meet_greet_label'] = \App\Support\AirportMatcher::isAirportSite($pickup) ? 'Airport pickup' : 'Meet & greet';
        $extras = $this->extraItems($options, $vehicleType);
        $extrasTotal = round(array_sum(array_column($extras, 'amount')), 2);

        $subtotal = $base === null ? null : round($baseWithSurcharge + $extrasTotal, 2);

        return [
            'base' => $base,
            'poa' => $base === null,
            'fixed' => $quote['fixed'],
            'surcharge' => $surcharge,
            'extras' => $extras,
            'extras_total' => $extrasTotal,
            'subtotal' => $subtotal,
        ];
    }

    /**
     * The holiday/rush window that contains this pickup time, or null. First match
     * wins (windows are non-overlapping in practice).
     *
     * @return array{label: string, factor: float}|null
     */
    public function holidayFactor(Carbon $pickupAt): ?array
    {
        // One festive table, read in one place (shared with the customer widget).
        return \App\Support\TimeSurcharges::festiveFactor($pickupAt);
    }

    /**
     * Itemised extras from the customer's selections, priced from the CET
     * surcharge list. Quantities are clamped to something sane.
     *
     * @param  array<string, int|bool>  $options
     * @return list<array{label: string, qty: int, unit: float, amount: float}>
     */
    private function extraItems(array $options, VehicleType $vehicleType): array
    {
        $rates = \App\Support\Surcharges::rates();
        $items = [];

        $flag = fn ($k) => ! empty($options[$k]);
        $qty = fn ($k) => max(0, min(20, (int) ($options[$k] ?? 0)));

        if ($flag('meet_greet') && ($rates['meet_greet'] ?? 0) > 0) {
            $items[] = $this->line($options['meet_greet_label'] ?? 'Meet & greet', 1, (float) $rates['meet_greet']);
        }
        foreach ([
            'child_seats' => ['Child seat', 'child_seat'],
            'booster_seats' => ['Booster seat', 'booster_seat'],
            'infant_seats' => ['Infant seat', 'infant_seat'],
            'stopovers' => ['Extra stop (via)', 'stopover'],
        ] as $field => [$label, $rateKey]) {
            if (($n = $qty($field)) > 0 && ($rates[$rateKey] ?? 0) > 0) {
                $items[] = $this->line($label, $n, (float) $rates[$rateKey]);
            }
        }

        // Ribbons: price by vehicle class (minibus vs car), like ETO.
        if ($flag('ribbons')) {
            $isMinibus = str_starts_with((string) $vehicleType->slug, 'minibus') || $vehicleType->slug === 'v-class';
            $unit = (float) ($isMinibus ? ($rates['ribbons_minibus'] ?? 0) : ($rates['ribbons_car'] ?? 0));
            if ($unit > 0) {
                $items[] = $this->line('Wedding ribbons', 1, $unit);
            }
        }

        // Wheelchair: shown but £0 by default (kept for completeness).
        if ($flag('wheelchair') && ($rates['wheelchair'] ?? 0) > 0) {
            $items[] = $this->line('Wheelchair', 1, (float) $rates['wheelchair']);
        }

        return $items;
    }

    /** @return array{label: string, qty: int, unit: float, amount: float} */
    private function line(string $label, int $qty, float $unit): array
    {
        return ['label' => $label, 'qty' => $qty, 'unit' => $unit, 'amount' => round($qty * $unit, 2)];
    }
}
