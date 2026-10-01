<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Office-editable booking settings. Values are stored in the `settings` table and
 * MERGED over config('cet.*') at boot (see AppServiceProvider), so every existing
 * `config('cet....')` read picks up the admin's value with no code change at the
 * read site. An unset value falls back to the config/.env default.
 */
class BookingSettings
{
    /** Each: setting key => [config key, cast]. VAT rate is handled specially (percent↔fraction). */
    public const MAP = [
        'booking.min_lead_hours' => ['cet.public_min_lead_hours', 'int'],
        'booking.estate_uplift' => ['cet.estate_over_executive', 'float'],
        'booking.review_delay_minutes' => ['cet.review_delay_minutes', 'int'],
        'booking.vat_registered' => ['cet.vat_registered', 'bool'],
        'booking.terms_url' => ['cet.links.terms', 'string'],
        'booking.privacy_url' => ['cet.links.privacy', 'string'],
        'booking.cancellation_url' => ['cet.links.cancellation', 'string'],
        'booking.ops_email' => ['cet.ops_email', 'string'],
    ];

    /** Merge stored settings over config('cet.*'). Safe to call every request. */
    public static function apply(): void
    {
        foreach (self::MAP as $settingKey => [$configKey, $cast]) {
            $raw = Setting::get($settingKey, null);
            if ($raw === null || $raw === '') {
                continue;
            }
            config([$configKey => match ($cast) {
                'int' => (int) $raw,
                'float' => (float) $raw,
                'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                default => (string) $raw,
            }]);
        }

        // VAT rate is shown/edited as a percentage but stored in config as a fraction.
        $vatPct = Setting::get('booking.vat_rate_percent', null);
        if ($vatPct !== null && $vatPct !== '') {
            config(['cet.vat_rate' => round(((float) $vatPct) / 100, 4)]);
        }
    }

    /** The effective values for the settings form (saved value, else the config default). */
    public static function current(): array
    {
        return [
            'min_lead_hours' => (int) config('cet.public_min_lead_hours', 8),
            'estate_uplift' => (float) config('cet.estate_over_executive', 10),
            'review_delay_minutes' => (int) config('cet.review_delay_minutes', 30),
            'vat_registered' => (bool) config('cet.vat_registered', true),
            'vat_rate_percent' => (int) round(((float) config('cet.vat_rate', 0.20)) * 100),
            'driver_pay_percent' => (int) Setting::get('driver_pay_percent', 90),
            'terms_url' => (string) config('cet.links.terms', ''),
            'privacy_url' => (string) config('cet.links.privacy', ''),
            'cancellation_url' => (string) config('cet.links.cancellation', ''),
            'ops_email' => (string) config('cet.ops_email', ''),
        ];
    }
}
