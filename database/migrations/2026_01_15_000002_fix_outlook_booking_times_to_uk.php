<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Companion to 000001: that migration corrected CSV imports (source != outlook).
 * This one corrects the Outlook/email-feed bookings (source = outlook), which
 * also stored UTC times — so BST pickups were an hour behind. Disjoint set from
 * 000001, so no booking is shifted twice. Skipped under testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $ids = DB::table('bookings')
            ->where('source_system', 'eto')
            ->where('source', 'outlook')
            ->pluck('id');

        foreach ($ids as $id) {
            $b = DB::table('bookings')->where('id', $id)->first();
            if ($b && $b->pickup_at) {
                DB::table('bookings')->where('id', $id)->update([
                    'pickup_at' => Carbon::parse($b->pickup_at, 'UTC')->setTimezone('Europe/London')->format('Y-m-d H:i:s'),
                ]);
            }

            $ev = DB::table('calendar_events')->where('booking_id', $id)->first();
            if ($ev) {
                $updates = [];
                foreach (['start_at', 'end_at'] as $col) {
                    if (! empty($ev->$col)) {
                        $updates[$col] = Carbon::parse($ev->$col, 'UTC')->setTimezone('Europe/London')->format('Y-m-d H:i:s');
                    }
                }
                if ($updates) {
                    DB::table('calendar_events')->where('id', $ev->id)->update($updates);
                }
            }
        }
    }

    public function down(): void
    {
        // no-op
    }
};
