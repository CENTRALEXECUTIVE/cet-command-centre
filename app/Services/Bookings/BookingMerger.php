<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\BookingTip;
use App\Models\Message;

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

        // Messages (reminders, review requests, sent history) move to the
        // survivor so nothing is orphaned or left firing for a booking that no
        // longer exists — this is what kept showing a duplicate WhatsApp
        // reminder after a merge.
        Message::where('booking_id', $dupe->id)->update(['booking_id' => $keep->id]);

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

        // Keep the duplicate's DRIVER-LINK token(s) alive as aliases on the
        // survivor: a link already sent to a driver for the merged-away copy must
        // still open this booking, not die when the copy is deleted.
        $aliases = (array) ($fill['meta']['driver_link_aliases'] ?? []);
        $carry = array_merge(
            [$dupe->driver_link_token ?? null, $dupe->meta['driver_link_token'] ?? null],
            (array) ($dupe->meta['driver_link_aliases'] ?? [])
        );
        foreach ($carry as $token) {
            if (filled($token) && $token !== ($keep->driver_link_token ?? null) && ! in_array($token, $aliases, true)) {
                $aliases[] = $token;
            }
        }
        if ($aliases) {
            $fill['meta']['driver_link_aliases'] = array_values($aliases);
        }

        // Remember EVERY reference this survivor now answers to. A later import
        // keyed by the merged-away reference must update THIS booking, not
        // re-create the duplicate and split them apart again. The reference that
        // ends up as the survivor's own external_reference isn't aliased.
        $finalRef = trim((string) ($fill['external_reference'] ?? $keep->external_reference));
        $refAliases = (array) ($fill['meta']['merged_references'] ?? []);
        $carryRefs = array_merge(
            [$dupe->external_reference, $keep->external_reference],
            (array) ($dupe->meta['merged_references'] ?? []),
            (array) ($keep->meta['merged_references'] ?? []),
        );
        foreach ($carryRefs as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '' && $ref !== $finalRef && ! in_array($ref, $refAliases, true)) {
                $refAliases[] = $ref;
            }
        }
        if ($refAliases) {
            $fill['meta']['merged_references'] = array_values($refAliases);
        }

        $keep->forceFill($fill)->save();

        $this->collapseDuplicateQueuedMessages($keep);
    }

    /**
     * After folding two bookings together the survivor can hold duplicate queued
     * messages of the same type — one from each copy. Two rules, per type:
     *   - if that type was ALREADY SENT on either copy, drop every queued one —
     *     the customer/driver was already messaged, so don't ask again (this is
     *     what re-asked a customer for a review after a merge);
     *   - otherwise keep a single queued message and drop the rest.
     * Sent history itself is left untouched.
     */
    private function collapseDuplicateQueuedMessages(Booking $keep): void
    {
        $all = $keep->messages()->get();
        $sentTypes = $all->where('status', 'sent')->pluck('type')->unique();

        $all->where('status', 'queued')
            ->groupBy('type')
            ->each(function ($group, $type) use ($sentTypes): void {
                // Already sent this type → no queued copy should remain.
                if ($sentTypes->contains($type)) {
                    $group->each(fn (Message $m) => $m->delete());

                    return;
                }
                // Otherwise keep the first, drop any duplicates.
                $group->sortBy('id')->slice(1)->each(fn (Message $m) => $m->delete());
            });
    }
}
