<?php

namespace App\Services\Pricing;

use App\Models\BankHoliday;
use App\Models\PricingRule;
use App\Models\VehicleType;
use Illuminate\Support\Carbon;

/**
 * Deterministic, rule-based fare calculation. Produces a transparent breakdown
 * (base + distance + time + airport surcharge + night/peak uplift + bank-holiday
 * surcharge, floored at the minimum fare) that the AI layer can review and
 * adjust. This is also the safe fallback when the AI is unavailable.
 */
class PricingRuleEngine
{
    /**
     * @return array{total: float, breakdown: array<string, mixed>}
     */
    public function quote(VehicleType $vehicleType, float $miles, int $minutes, Carbon $pickupAt, bool $isAirport = false): array
    {
        $rule = PricingRule::where('vehicle_type_id', $vehicleType->id)->where('is_active', true)->first();

        // Sensible defaults if no rule has been configured for this class.
        $base = (float) ($rule->base_fare ?? 6.00);
        $perMile = (float) ($rule->per_mile ?? 2.20);
        $perMinute = (float) ($rule->per_minute ?? 0.25);
        $minFare = (float) ($rule->minimum_fare ?? 12.00);
        $airportSurcharge = $isAirport ? (float) ($rule->airport_surcharge ?? 5.00) : 0.0;

        $distanceCost = round($perMile * $miles, 2);
        $timeCost = round($perMinute * $minutes, 2);
        $running = $base + $distanceCost + $timeCost + $airportSurcharge;

        // Time-of-day uplift (night band).
        $nightUplift = 0.0;
        $nightMultiplier = (float) ($rule->night_multiplier ?? 1.0);
        if ($nightMultiplier > 1.0 && $this->inNightBand($rule, $pickupAt)) {
            $nightUplift = round($running * ($nightMultiplier - 1), 2);
        }

        // Bank-holiday surcharge.
        $bankHolidayUplift = 0.0;
        $holiday = BankHoliday::whereDate('date', $pickupAt->toDateString())->first();
        if ($holiday && $holiday->surcharge_percent > 0) {
            $bankHolidayUplift = round(($running + $nightUplift) * ((float) $holiday->surcharge_percent / 100), 2);
        }

        $total = max($minFare, round($running + $nightUplift + $bankHolidayUplift, 2));

        return [
            'total' => $total,
            'breakdown' => [
                'base_fare' => round($base, 2),
                'distance' => ['miles' => $miles, 'per_mile' => $perMile, 'cost' => $distanceCost],
                'time' => ['minutes' => $minutes, 'per_minute' => $perMinute, 'cost' => $timeCost],
                'airport_surcharge' => $airportSurcharge,
                'night_uplift' => $nightUplift,
                'bank_holiday' => ['name' => $holiday?->name, 'percent' => (float) ($holiday->surcharge_percent ?? 0), 'uplift' => $bankHolidayUplift],
                'minimum_fare' => $minFare,
                'minimum_applied' => $total === $minFare && $minFare > round($running + $nightUplift + $bankHolidayUplift, 2),
            ],
        ];
    }

    private function inNightBand(?PricingRule $rule, Carbon $pickupAt): bool
    {
        if (! $rule || ! $rule->night_starts_at || ! $rule->night_ends_at) {
            return false;
        }

        $time = $pickupAt->format('H:i:s');
        $start = Carbon::parse($rule->night_starts_at)->format('H:i:s');
        $end = Carbon::parse($rule->night_ends_at)->format('H:i:s');

        // Overnight window (e.g. 22:00 → 06:00) wraps midnight.
        return $start <= $end
            ? ($time >= $start && $time < $end)
            : ($time >= $start || $time < $end);
    }
}
