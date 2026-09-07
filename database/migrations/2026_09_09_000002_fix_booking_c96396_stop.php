<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

/**
 * URGENT one-off data repair (auto-applied by auto-deploy's `migrate --force`).
 *
 * Booking CET-C96396 (ETO ref WX9KDO — Nathan Haddad, LHR, V Class) had a WRONG
 * via stop edited into the app: the driver screen showed "Stop 1: Shirebrook,
 * Mansfield". The operator's Google Calendar (the source of truth, verified
 * read-only) carries the correct single stop:
 *
 *     Via: Hilton London Heathrow Airport, Terminal 4, Hounslow, UK
 *
 * A previous repair (…000001…) cleared other bad edits but this stop was still
 * in the booking's stops table, which outranks the calendar. This pins the ONE
 * correct stop directly (so a driver already on the job sees the right place
 * regardless of what else is refreshed), clears the competing sources, and keeps
 * the correct pickup / drop-off. The Google Calendar is NEVER touched. A no-op in
 * tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $booking = Booking::where('reference', 'CET-C96396')->first()
            ?? Booking::where('reference', 'like', '%C96396%')->first()
            ?? Booking::where('external_reference', 'WX9KDO')->first();
        if (! $booking) {
            return; // not this environment, or already gone
        }

        $correctPickup = 'Wildes Inn, Worksop Rd, Clowne, Chesterfield S43 4TD, UK';
        $correctVia = 'Hilton London Heathrow Airport, Terminal 4, Hounslow, UK';
        $correctDropoff = '174 Willifield Way, London NW11 6YD, UK';

        $meta = $booking->meta ?? [];
        unset(
            $meta['edited_fields'], $meta['manually_edited_at'],
            $meta['stops'], $meta['stops_reached'], $meta['stop_events'], $meta['eto_via'],
        );

        // Replace whatever stops exist with the single correct one.
        $booking->stops()->delete();
        $booking->stops()->create(['sequence' => 1, 'address' => $correctVia]);

        $booking->forceFill([
            'pickup_address' => $correctPickup,
            'destination_address' => $correctDropoff,
            'meta' => $meta,
        ])->save();
    }

    public function down(): void
    {
        // No-op: a data correction is not reversible.
    }
};
