<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data correction. Booking N8CAAJb had its passenger count clobbered to
 * 1 (the import default) when the office edited only the pickup time: the old
 * edit form pre-filled the passengers box from the raw column, so an untouched
 * field was submitted as a stale "1" and stamped as a manual edit. The form fix
 * stops this recurring; this heals the one booking already damaged.
 *
 * Real head-count for this booking is 7. We set the column to 7 and drop the
 * stale 'passengers' entry from edited_fields so the value agrees with the
 * calendar again. Guarded to a no-op outside the booking's own environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $booking = DB::table('bookings')->where('reference', 'N8CAAJb')->first();
        if (! $booking) {
            return; // not this environment — nothing to fix
        }

        $meta = json_decode($booking->meta ?? '[]', true) ?: [];
        $edited = $meta['edited_fields'] ?? null;
        if (is_array($edited)) {
            $meta['edited_fields'] = array_values(array_filter(
                $edited,
                fn ($f) => $f !== 'passengers'
            ));
        }

        DB::table('bookings')->where('id', $booking->id)->update([
            'passengers' => 7,
            'meta' => json_encode($meta),
        ]);
    }

    public function down(): void
    {
        // No-op: a data correction is not reversible.
    }
};
