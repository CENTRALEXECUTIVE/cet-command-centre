<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

/**
 * ONE-OFF data repair, applied automatically by auto-deploy's `migrate --force`
 * so the office doesn't have to run a command.
 *
 * Booking CET-C96396 (ETO ref WX9KDO — Nathan Haddad, LHR, V Class) was garbled
 * by a manual edit in the app: the drop-off was duplicated as a via stop, so the
 * driver screen showed "Stop 1: Hilton Heathrow" AND "Drop-off: Hilton Heathrow"
 * — while the Google Calendar event (the operator's source of truth, verified
 * read-only) still carried the CORRECT journey:
 *
 *     Pickup   Wildes Inn, Worksop Rd, Clowne, Chesterfield S43 4TD, UK
 *     Via      Hilton London Heathrow Airport, Terminal 4, Hounslow, UK
 *     Drop-off 174 Willifield Way, London NW11 6YD, UK
 *
 * This clears the bad edit so the app mirrors the calendar again: it removes the
 * duplicated stop and the per-field "edited" flags, and pins the correct pickup /
 * via / drop-off. The calendar itself is NEVER touched. Idempotent, and a no-op
 * in tests.
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

        // Already repaired? nothing to do.
        $alreadyFixed = $booking->destination_address === $correctDropoff
            && $booking->pickup_address === $correctPickup
            && empty($meta['edited_fields'])
            && empty($meta['manually_edited_at'])
            && $booking->stops()->count() === 0;
        if ($alreadyFixed) {
            return;
        }

        // Drop the duplicated stop rows and any half-finished stop progress.
        $booking->stops()->delete();
        unset($meta['stops'], $meta['stops_reached'], $meta['stop_events']);

        // Stop overriding the calendar per-field — the app should mirror the
        // (correct) calendar again for this booking.
        unset($meta['edited_fields'], $meta['manually_edited_at']);

        // Keep the one real via stop as the ETO free-text "Via" so it still shows
        // on the driver screen even independently of the calendar description.
        $meta['eto_via'] = $correctVia;

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
