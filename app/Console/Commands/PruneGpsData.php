<?php

namespace App\Console\Commands;

use App\Services\DriverLocationService;
use Illuminate\Console\Command;

/**
 * GDPR retention: deletes GPS pings older than the retention window
 * (config cet.gps_retention_days, default 90). Scheduled daily.
 */
class PruneGpsData extends Command
{
    protected $signature = 'cet:prune-gps';

    protected $description = 'Delete GPS location pings older than the retention window (GDPR)';

    public function handle(DriverLocationService $locations): int
    {
        $removed = $locations->prune();
        $this->info("Pruned {$removed} GPS ping(s) older than ".config('cet.gps_retention_days', 90).' days.');

        // Watchdog housekeeping (30-day retention — the alerts feed is a live
        // log, not an archive; the booking history remains the record).
        $events = \App\Models\WatchdogEvent::where('occurred_at', '<', now()->subDays(30))->delete();
        $nudges = \App\Models\JobNudge::where('sent_at', '<', now()->subDays(30))->delete();
        $this->info("Pruned {$events} watchdog event(s) and {$nudges} nudge record(s) older than 30 days.");

        return self::SUCCESS;
    }
}
