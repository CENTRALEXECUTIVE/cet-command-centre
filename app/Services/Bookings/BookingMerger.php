<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\BookingTip;

/**
 * Folds one booking (the duplicate) into another (the one we keep) without
 * losing anything — a "replace", not a bare delete. Money (tips), the Google
 * calendar link, the ETO reference and any blank fields all move across to the
 * survivor, then the duplicate is removed. Shared by the dedupe command and the
 * manual "merge duplicate" button on the booking page.
 */
class BookingMerger
{
    /** Fold everything useful from $dupe into $keep, then delete $dupe. */
    public function mergeAndDelete(Booking $keep, Booking $dupe): void
    {
        $this->merge($keep, $dupe);
        $dupe->forceDelete();
    }

    /** Fold everything useful from $dupe into $keep (does NOT delete $dupe). */
    public function merge(Booking $keep, Booking $dupe): void
    {
        // Money moves with the journey — reassign any tips to the survivor.
        BookingTip::where('booking_id', $dupe->id)->update(['booking_id' => $keep->id]);

        // Take the calendar link if the survivor hasn't got one.
        if (! $keep->calendarEvent && $dupe->calendarEvent) {
            $dupe->calendarEvent->forceFill(['booking_id' => $keep->id])->save();
        }

        $fill = [];

        // Take the ETO reference if the survivor is missing one.
        if (blank($keep->external_reference) && filled($dupe->external_reference)) {
            $fill['external_reference'] = $dupe->external_reference;
        }

        // Fill any blank fields on the survivor from the duplicate — including
        // the driver, so merging never loses an allocation.
        foreach ([
            'driver_id', 'vehicle_id', 'airport_id', 'quoted_price', 'final_price',
            'flight_number', 'destination_address', 'pickup_address', 'special_requests',
        ] as $col) {
            if (blank($keep->$col) && filled($dupe->$col)) {
                $fill[$col] = $dupe->$col;
            }
        }

        // Merge meta so nothing (payroll, notes, tokens, audit) is lost — the
        // survivor's own values win any conflict.
        $fill['meta'] = array_merge($dupe->meta ?? [], $keep->meta ?? []);

        $keep->forceFill($fill)->save();
    }
}
