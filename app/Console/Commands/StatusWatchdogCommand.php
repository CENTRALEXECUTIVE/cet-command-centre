<?php

namespace App\Console\Commands;

use App\Services\Watchdog\StatusWatchdog;
use Illuminate\Console\Command;

/**
 * Runs the status watchdog once (scheduled every minute). Idempotent — each
 * nudge type fires at most twice per job, tracked in job_nudges, so running
 * this five times in a minute sends exactly what one run would.
 */
class StatusWatchdogCommand extends Command
{
    protected $signature = 'cet:status-watchdog';

    protected $description = 'Nudge drivers (set off / arrived / POB / complete) and log watchdog events';

    public function handle(StatusWatchdog $watchdog): int
    {
        $sent = $watchdog->run();

        $this->info("Watchdog pass complete — {$sent} nudge(s) sent.");

        return self::SUCCESS;
    }
}
