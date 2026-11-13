<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off tidy (13 Nov 2026): the 5-minute payment retry stacked a new "Email didn't send"
 * alert every run during the Microsoft outage. Keep the NEWEST un-ticked alert for each
 * email and tick off the repeats, so the dashboard is usable again. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('watchdog_events')) {
            return;
        }

        $open = DB::table('watchdog_events')
            ->where('event_type', 'email_failed')
            ->whereNull('acknowledged_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'booking_id']);

        $seen = [];
        $repeats = [];
        foreach ($open as $e) {
            $key = $e->title.'|'.($e->booking_id ?? '');
            if (isset($seen[$key])) {
                $repeats[] = $e->id;
            }
            $seen[$key] = true;
        }

        foreach (array_chunk($repeats, 500) as $ids) {
            DB::table('watchdog_events')->whereIn('id', $ids)->update(['acknowledged_at' => now()]);
        }
    }

    public function down(): void {}
};
