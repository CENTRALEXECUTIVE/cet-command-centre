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

    /**
     * THE single source of truth for time-based pricing, used by the customer widget
     * AND the admin form (QuoteService::quote with a pickup time; FareCalculator calls
     * that). Combines:
     *   - the directors' festive table (config cet.holiday_surcharges, e.g. Christmas
     *     Day +50%), and
     *   - the office Settings rules (night window, dated uplifts — TimeSurcharges).
     *
     * Every surcharge is calculated on the STANDARD journey fare ($base: trip + drag,
     * never extras) and added — one surcharge is never compounded on another. When a
     * festive window covers this moment, the festive table wins: an office DATED uplift
     * for the same day is not charged as well. The night window is a time-of-day rate,
     * not a festive one, so it still adds. A return leg is priced with its OWN pickup
     * time by the caller.
     *
     * @return array{amount: float, label: string|null, factor: float|null}
     */
    public static function forStandardFare(?Carbon $at, float $base): array
    {
        if ($at === null || $base <= 0) {
            return ['amount' => 0.0, 'label' => null, 'factor' => null];
        }

        $total = 0.0;
        $labels = [];

        $festive = self::festiveFactor($at);
        if ($festive) {
            $total += round($base * ($festive['factor'] - 1), 2);
            $labels[] = $festive['label'];
        }

        $rules = self::rules();
        $night = $rules['night'];
        if (! empty($night['enabled']) && self::inNightWindow($at, (string) $night['from'], (string) $night['to'])) {
            $total += round(self::amount((string) ($night['type'] ?? 'percent'), (float) ($night['value'] ?? 0), $base), 2);
            $labels[] = 'night rate';
        }

        if (! $festive) { // festive table wins over an office dated uplift for the same moment
            $ymd = $at->toDateString();
            foreach ($rules['dates'] as $d) {
                if (($d['date'] ?? null) === $ymd) {
                    $total += round(self::amount((string) ($d['type'] ?? 'percent'), (float) ($d['value'] ?? 0), $base), 2);
                    $labels[] = $d['label'] ?? 'holiday rate';
                }
            }
        }

        $total = round($total, 2);

        // Directors (8 Nov 2026): a fare with a holiday/time rate is rounded UP to the
        // next £5 (£131.25 → £135), so quotes are round figures and never under-charge.
        // The surcharge absorbs the rounding; flat extras (meet & greet, seats) are
        // added afterwards and stay as they are. Website and admin share this rule.
        if ($total > 0) {
            $total = round(ceil(round($base + $total, 2) / 5) * 5 - $base, 2);
        }

        return [
            'amount' => $total,
            'label' => $total > 0 && $labels ? implode(' + ', $labels) : null,
            'factor' => $festive['factor'] ?? null,
        ];
    }

    /**
     * The festive-table window (config cet.holiday_surcharges) containing this pickup,
     * or null. Rules match on month-day + time of day, so the table recurs every year
     * with no edits; a window never crosses year-end, so a zero-padded "MM-DD HH:MM"
     * string compares chronologically. First match wins.
     *
     * @return array{label: string, factor: float}|null
     */
    public static function festiveFactor(Carbon $at): ?array
    {
        $key = $at->format('m-d H:i');

        foreach ((array) config('cet.holiday_surcharges', []) as $rule) {
            $from = self::monthDayKey((string) ($rule['from'] ?? ''));
            $to = self::monthDayKey((string) ($rule['to'] ?? ''));
            if ($from === null || $to === null) {
                continue;
            }
            if ($key >= $from && $key <= $to && (float) $rule['factor'] !== 1.0) {
                return ['label' => (string) $rule['label'], 'factor' => (float) $rule['factor']];
            }
        }

        return null;
    }

    /**
     * Normalise a festive-window bound to "MM-DD HH:MM". Accepts the recurring
     * "MM-DD HH:MM" / "MM-DD" form, or a full "YYYY-MM-DD HH:MM" (year ignored).
     */
    private static function monthDayKey(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-/', $v)) {
            return Carbon::parse($v, config('app.timezone'))->format('m-d H:i');
        }
        if (preg_match('/^(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?$/', $v, $m)) {
            return sprintf('%s-%s %s:%s', $m[1], $m[2], $m[3] ?? '00', $m[4] ?? '00');
        }

        return null;
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
