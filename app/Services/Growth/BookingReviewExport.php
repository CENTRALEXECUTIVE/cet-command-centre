<?php

namespace App\Services\Growth;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * The detailed bookings export for a business review ("Export for review" on
 * the Growth dashboard and the Review page). One row per booking leg that CAME
 * IN between two dates, with everything needed to analyse where bookings come
 * from and what they're worth: booked/pickup dates and weekdays, lead time,
 * channel (social / website / Google Ads / accounts / phone), UTM tags, vehicle,
 * airport, area (postcode district only), fare ex-VAT, VAT or not, payment,
 * account, new vs returning customer and the customer's booking number.
 *
 * PRIVACY: it is designed to be shared for analysis, so it carries NO customer
 * names, phone numbers, emails or full addresses. Customers appear only as an
 * internal number; places only as the postcode district (e.g. S10, DN4).
 */
class BookingReviewExport
{
    public const COLUMNS = [
        'reference', 'booked_date', 'booked_time', 'booked_weekday', 'pickup_date', 'pickup_time',
        'pickup_weekday', 'lead_days', 'status', 'journey_type', 'return_leg', 'vehicle', 'airport',
        'flight_given', 'pickup_area', 'dropoff_area', 'passengers', 'luggage', 'fare_ex_vat',
        'vat_invoice', 'payment_method', 'payment_status', 'paid_amount', 'source', 'imported_from',
        'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'ad_click', 'business_account',
        'customer_no', 'customer_type', 'customer_booking_no', 'driver',
    ];

    /** @return iterable<array<int, mixed>> rows matching COLUMNS */
    /**
     * $by = 'booked' (default): bookings that CAME IN between the dates.
     * $by = 'pickup': bookings whose PICKUP falls between the dates, e.g. every
     * future job already in the books (for the prepaid-jobs cash check).
     */
    public function rows(Carbon $from, Carbon $to, string $by = 'booked'): iterable
    {
        $tz = config('app.timezone', 'Europe/London');

        $bookings = Booking::query()
            ->with(['vehicleType', 'airport', 'corporateAccount', 'driver', 'payments'])
            ->whereBetween($by === 'pickup' ? 'pickup_at' : 'created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'demo'))
            ->orderBy('created_at')->orderBy('id')
            ->get();

        // Each customer's bookings in the order they came in (outbound legs only),
        // so we can say whether this was their 1st, 2nd, 3rd… booking.
        $sequence = [];
        $customerIds = $bookings->pluck('customer_id')->filter()->unique()->values();
        if ($customerIds->isNotEmpty()) {
            Booking::query()->whereIn('customer_id', $customerIds)
                ->where(fn ($q) => $q->whereNull('is_return_leg')->orWhere('is_return_leg', false))
                ->whereNotIn('status', ['cancelled'])
                ->orderBy('created_at')->orderBy('id')
                ->get(['id', 'customer_id'])
                ->each(function ($b) use (&$sequence) {
                    $sequence[$b->customer_id] = ($sequence[$b->customer_id] ?? 0) + 1;
                    $sequence['b'.$b->id] = $sequence[$b->customer_id];
                });
        }

        foreach ($bookings as $b) {
            $booked = $b->created_at?->copy()->setTimezone($tz);
            $pickup = $b->pickup_at?->copy()->setTimezone($tz);
            $m = (array) (($b->meta ?? [])['marketing'] ?? []);
            $nth = $sequence['b'.$b->id] ?? null;
            $paid = $b->relationLoaded('payments')
                ? (float) $b->payments->where('status', 'paid')->sum('amount') : null;

            yield [
                $b->reference,
                $booked?->format('Y-m-d'),
                $booked?->format('H:i'),
                $booked?->format('D'),
                $pickup?->format('Y-m-d'),
                $pickup?->format('H:i'),
                $pickup?->format('D'),
                ($booked && $pickup) ? round($booked->diffInHours($pickup, false) / 24, 1) : null,
                $b->status?->value,
                $b->journey_type,
                $b->is_return_leg ? 'yes' : 'no',
                $b->displayVehicleType(),
                $b->airport?->code,
                filled($b->flight_number) ? 'yes' : 'no',
                self::district($b->pickup_postcode, $b->pickup_address),
                self::district($b->destination_postcode, $b->destination_address),
                $b->passengerCount(),
                $b->luggage,
                $b->fareAmount(),
                ($b->meta['vat_invoice_requested'] ?? false) ? 'yes' : 'no',
                $b->payment_method?->value,
                $b->payment_status,
                $paid,
                $b->source,
                $b->source_system,
                GrowthDashboard::CHANNELS[GrowthDashboard::channelFor($b)] ?? 'Other',
                $m['utm_source'] ?? null,
                $m['utm_medium'] ?? null,
                $m['utm_campaign'] ?? null,
                (filled($m['gclid'] ?? null) || filled($m['gbraid'] ?? null) || filled($m['wbraid'] ?? null)) ? 'yes' : 'no',
                $b->corporateAccount?->name,
                $b->customer_id,
                $b->is_return_leg ? 'return leg' : ($nth === null ? null : ($nth === 1 ? 'new' : 'returning')),
                $nth,
                $b->driver?->name,
            ];
        }
    }

    /** The postcode district (outward code) only, e.g. "S10" or "DN4"; never the full postcode. */
    public static function district(?string $postcode, ?string $address = null): ?string
    {
        foreach ([$postcode, $address] as $text) {
            if (! filled($text)) {
                continue;
            }
            if (preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?)\s*\d[A-Z]{2}\b/i', (string) $text, $mm)) {
                return strtoupper($mm[1]);
            }
        }

        return null;
    }
}
