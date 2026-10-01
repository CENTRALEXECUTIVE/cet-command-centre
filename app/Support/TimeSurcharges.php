<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Office-defined time-based pricing: a night surcharge (a window like 22:00–06:00) and
 * date uplifts (Christmas, New Year's Eve, …). Stored as one JSON setting, applied to a
 * fare for a given pickup time. Zero by default — nothing changes until it's configured.
 */
class TimeSurcharges
{
    public const KEY = 'pricing.time_surcharges';

    /** @return array{night: array, dates: list<array>} */
    public static function rules(): array
    {
        $raw = Setting::get(self::KEY, null);
        $data = is_array($raw) ? $raw : [];

        return [
            'night' => $data['night'] ?? ['enabled' => false, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 0],
            'dates' => array_values($data['dates'] ?? []),
        ];
    }

    public static function save(array $night, array $dates): void
    {
        Setting::set(self::KEY, ['night' => $night, 'dates' => array_values($dates)], 'json', 'pricing');
    }

    /** The £ surcharge to add to $base for a pickup at $at (night + any matching date). */
    public static function surchargeFor(?Carbon $at, float $base): float
    {
        if ($at === null || $base <= 0) {
            return 0.0;
        }

        $rules = self::rules();
        $total = 0.0;

        $night = $rules['night'];
        if (! empty($night['enabled']) && self::inNightWindow($at, (string) $night['from'], (string) $night['to'])) {
            $total += self::amount((string) ($night['type'] ?? 'percent'), (float) ($night['value'] ?? 0), $base);
        }

        $ymd = $at->toDateString();
        foreach ($rules['dates'] as $d) {
            if (($d['date'] ?? null) === $ymd) {
                $total += self::amount((string) ($d['type'] ?? 'percent'), (float) ($d['value'] ?? 0), $base);
            }
        }

        return round($total, 2);
    }

    /** A short label for the applied surcharge(s), e.g. "night rate" / "Christmas Day", or null. */
    public static function labelFor(?Carbon $at): ?string
    {
        if ($at === null) {
            return null;
        }
        $rules = self::rules();
        $labels = [];

        $night = $rules['night'];
        if (! empty($night['enabled']) && self::inNightWindow($at, (string) $night['from'], (string) $night['to'])) {
            $labels[] = 'night rate';
        }
        $ymd = $at->toDateString();
        foreach ($rules['dates'] as $d) {
            if (($d['date'] ?? null) === $ymd) {
                $labels[] = $d['label'] ?? 'holiday rate';
            }
        }

        return $labels ? implode(' + ', $labels) : null;
    }

    private static function amount(string $type, float $value, float $base): float
    {
        return $type === 'percent' ? $base * ($value / 100) : $value;
    }

    /** True when the HH:MM of $at falls in [from, to), handling an overnight wrap. */
    private static function inNightWindow(Carbon $at, string $from, string $to): bool
    {
        $mins = fn (string $hhmm) => (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
        $now = $at->hour * 60 + $at->minute;
        $f = $mins($from);
        $t = $mins($to);

        return $f <= $t ? ($now >= $f && $now < $t) : ($now >= $f || $now < $t);
    }
}
