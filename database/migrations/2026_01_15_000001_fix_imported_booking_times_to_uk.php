<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ETO CSV imports stored pickup times in UTC/GMT, so BST pickups were an hour
 * behind (14:10 shown for a 15:10 pickup). Re-interpret those stored times as
 * UTC and convert to UK local (Europe/London) — +1h in BST, +0h in GMT — for the
 * booking and its calendar-event mirror. Only CSV imports (source_system=eto,
 * not the Outlook/email feed which already stored UK-local) are touched; the
 * Google Calendar itself is NOT altered. Skipped under testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(fn (string $raw) => Carbon::parse($raw, 'UTC')->setTimezone('Europe/London'));
    }

    public function down(): void
    {
        // Reverse: UK local back to UTC.
        $this->shift(fn (string $raw) => Carbon::parse($raw, 'Europe/London')->setTimezone('UTC'));
    }

    private function shift(callable $convert): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $ids = DB::table('bookings')
            ->where('source_system', 'eto')
            ->where(fn ($q) => $q->where('source', '!=', 'outlook')->orWhereNull('source'))
            ->pluck('id');

        foreach ($ids as $id) {
            $b = DB::table('bookings')->where('id', $id)->first();
            if ($b && $b->pickup_at) {
                DB::table('bookings')->where('id', $id)
                    ->update(['pickup_at' => $convert($b->pickup_at)->format('Y-m-d H:i:s')]);
            }

            $ev = DB::table('calendar_events')->where('booking_id', $id)->first();
            if ($ev) {
                $updates = [];
                foreach (['start_at', 'end_at'] as $col) {
                    if (! empty($ev->$col)) {
                        $updates[$col] = $convert($ev->$col)->format('Y-m-d H:i:s');
                    }
                }
                if ($updates) {
                    DB::table('calendar_events')->where('id', $ev->id)->update($updates);
                }
            }
        }
    }
};
