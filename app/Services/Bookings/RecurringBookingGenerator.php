<?php

namespace App\Services\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\RecurringBooking;
use Illuminate\Support\Carbon;

/**
 * Turns active standing bookings into real pending bookings for their upcoming
 * occurrences, a few days ahead. Idempotent — it never creates a second booking for
 * the same template + date, so it's safe to run daily (or on demand).
 */
class RecurringBookingGenerator
{
    /** Generate every due occurrence across all active templates. Returns the count. */
    public function generateDue(): int
    {
        $count = 0;
        foreach (RecurringBooking::where('is_active', true)->get() as $template) {
            $count += $this->generateForTemplate($template);
        }

        return $count;
    }

    /** Generate the occurrences for one template within its lead window. */
    public function generateForTemplate(RecurringBooking $template): int
    {
        $today = Carbon::today(config('app.timezone'));
        $end = $today->copy()->addDays(max(0, (int) $template->lead_days));
        $made = 0;

        for ($date = $today->copy(); $date->lte($end); $date->addDay()) {
            if (! $template->occursOn($date)) {
                continue;
            }
            if ($this->generateOne($template, $date->copy())) {
                $made++;
            }
        }

        if ($made > 0) {
            $template->forceFill(['last_generated_on' => $today])->save();
        }

        return $made;
    }

    /** Create one booking for a template on a date, unless it already exists. */
    public function generateOne(RecurringBooking $template, Carbon $date): ?Booking
    {
        [$h, $m] = array_pad(explode(':', $template->pickup_time ?: '09:00'), 2, '00');
        $pickupAt = $date->copy()->setTime((int) $h, (int) $m);

        // Never in the past, and never a duplicate for this template + day.
        if ($pickupAt->isPast()) {
            return null;
        }
        $exists = Booking::where('meta->recurring_id', $template->id)
            ->whereDate('pickup_at', $pickupAt->toDateString())
            ->exists();
        if ($exists) {
            return null;
        }

        $pickup = $this->withPostcode($template->pickup_address, $template->pickup_postcode);
        $dropoff = $this->withPostcode($template->destination_address, $template->destination_postcode);

        return Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $template->customer_id,
            'vehicle_type_id' => $template->vehicle_type_id,
            'journey_type' => 'one_way',
            'pickup_at' => $pickupAt,
            'pickup_address' => $pickup,
            'pickup_postcode' => $template->pickup_postcode,
            'destination_address' => $dropoff,
            'destination_postcode' => $template->destination_postcode,
            'passengers' => $template->passengers ?: 1,
            'special_requests' => $template->notes,
            'status' => BookingStatus::Pending->value,
            'payment_method' => $template->payment_method ?: 'cash',
            'payment_status' => 'pending',
            'source' => 'recurring',
            'meta' => array_filter([
                'recurring_id' => $template->id,
                'driver_notes' => $template->notes,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    private function withPostcode(?string $address, ?string $postcode): string
    {
        $address = trim((string) $address);
        $postcode = strtoupper(trim((string) $postcode));
        if ($postcode === '' || stripos($address, $postcode) !== false) {
            return $address;
        }

        return $address === '' ? $postcode : $address.', '.$postcode;
    }
}
