<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Silent log of every website booking attempt (booked / quote request / refused /
 * error) and every price lookup that showed "On request" (26 Nov 2026). Never shown to
 * customers; admins read it at /booking-log. Kept 90 days. Recording never breaks the
 * customer's booking — a failure here is swallowed.
 */
class BookingAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public static function record(string $form, string $outcome, ?Request $request = null, array $extra = []): void
    {
        try {
            $in = $request?->all() ?? [];
            $name = trim(($in['customer_first_name'] ?? '').' '.($in['customer_surname'] ?? '')) ?: ($in['customer_name'] ?? null);
            $contact = trim(implode(' · ', array_filter([$in['customer_phone'] ?? null, $in['customer_email'] ?? null])));
            // Always note the vehicle the customer chose, even if no booking was made.
            if (filled($in['vehicle_type_id'] ?? null) && empty($extra['details']['vehicle'])) {
                $extra['details'] = array_merge((array) ($extra['details'] ?? []), [
                    'vehicle' => \App\Models\VehicleType::whereKey((int) $in['vehicle_type_id'])->value('name'),
                ]);
            }
            // Everything the customer typed (never passwords or tokens), so the office can
            // open the entry, see it all and make the booking from it (27 Nov 2026).
            $typed = collect($in)->except(['password', 'password_confirmation', '_token', 'submission_token', 'company', 'viewport', 'beacon_kind', 'beacon_message', 'beacon_step'])
                // Lists (e.g. via stops) are kept too, joined with " | ".
                ->map(fn ($v) => is_array($v) ? implode(' | ', array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v))) : $v)
                ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
                ->map(fn ($v) => mb_substr((string) $v, 0, 1000))->all();
            if ($typed !== []) {
                $extra['details'] = array_merge((array) ($extra['details'] ?? []), ['form' => $typed]);
            }
            static::create(array_merge([
                'form' => $form,
                'outcome' => $outcome,
                'customer_name' => $name ? mb_substr($name, 0, 160) : null,
                'contact' => $contact !== '' ? mb_substr($contact, 0, 190) : null,
                'pickup' => mb_substr((string) ($in['pickup_address'] ?? $in['pickup'] ?? ''), 0, 500) ?: null,
                'destination' => mb_substr((string) ($in['destination_address'] ?? $in['destination'] ?? ''), 0, 500) ?: null,
                'pickup_at' => mb_substr((string) ($in['pickup_at'] ?? ''), 0, 40) ?: null,
                'created_at' => now(),
            ], $extra));
        } catch (\Throwable $e) {
            // table not migrated yet / DB blip — never affect the customer
            \Illuminate\Support\Facades\Log::warning('[booking-log] '.$e->getMessage());
        }
    }
}
