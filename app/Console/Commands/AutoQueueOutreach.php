<?php

namespace App\Console\Commands;

use App\Services\Outreach\OutreachBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily outreach on autopilot (directors, 18 Nov 2026: "automate them for the next
 * days — I don't have to always say"). Each weekday from 09:00 UK, queue the day's
 * batch 5 minutes apart. Idempotent: nothing happens if anything was already sent or
 * queued today. Off switch: Setting outreach_auto_send = 0.
 */
class AutoQueueOutreach extends Command
{
    protected $signature = 'cet:outreach-auto-queue';

    protected $description = 'Queue today\'s outreach batch automatically (weekdays, 09:00, 5 min apart)';

    public function handle(OutreachBatch $batch): int
    {
        if (! OutreachBatch::autoSendEnabled()) {
            return self::SUCCESS;
        }
        $tz = config('app.timezone', 'Europe/London');
        $n = $batch->autoQueueToday(Carbon::now($tz)->setTime(9, 0));
        if ($n) {
            $this->info("Queued {$n} outreach email(s) for today.");
        }

        return self::SUCCESS;
    }
}
