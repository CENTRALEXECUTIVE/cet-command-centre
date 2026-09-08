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
        $quote = $this->quotes->quote($pickup, $destination, $vehicleType);
        $base = $quote['price'];

        $surcharge = null;
        $baseWithSurcharge = $base;
        if ($base !== null && $factor = $this->holidayFactor($pickupAt)) {
            $amount = round($base * ($factor['factor'] - 1), 2);
            $baseWithSurcharge = round($base + $amount, 2);
            $surcharge = ['label' => $factor['label'], 'factor' => $factor['factor'], 'amount' => $amount];
        }

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
        foreach ((array) config('cet.holiday_surcharges', []) as $rule) {
            $from = Carbon::parse($rule['from'], config('app.timezone'));
            $to = Carbon::parse($rule['to'], config('app.timezone'));
            if ($pickupAt->betweenIncluded($from, $to) && (float) $rule['factor'] !== 1.0) {
                return ['label' => (string) $rule['label'], 'factor' => (float) $rule['factor']];
            }
        }

        return null;
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
        $rates = (array) config('cet.surcharges', []);
        $items = [];

        $flag = fn ($k) => ! empty($options[$k]);
        $qty = fn ($k) => max(0, min(20, (int) ($options[$k] ?? 0)));

        if ($flag('meet_greet') && ($rates['meet_greet'] ?? 0) > 0) {
            $items[] = $this->line('Meet & greet', 1, (float) $rates['meet_greet']);
        }
        foreach ([
            'child_seats' => ['Child seat', 'child_seat'],
            'booster_seats' => ['Booster seat', 'booster_seat'],
            'infant_seats' => ['Infant seat', 'infant_seat'],
            'stopovers' => ['Extra stop (via)', 'stopover'],
            'hire_hours' => ['Hourly hire', 'hire_hour'],
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
