<?php

namespace App\Console\Commands;

use App\Services\Outreach\OutreachBatch;
use Illuminate\Console\Command;

/**
 * Sends approved outreach emails that a director chose to space out ("one every
 * 5 minutes") on /outreach/batch. Only emails a director ticked and approved are
 * ever in the queue; each is re-checked at send time. Runs every minute.
 */
class SendQueuedOutreach extends Command
{
    protected $signature = 'cet:outreach-send-queued';

    protected $description = 'Send approved outreach emails whose spaced send time has come';

    public function handle(OutreachBatch $batch): int
    {
        $r = $batch->sendDue();
        if ($r['sent'] || $r['dropped'] || $r['failed']) {
            $this->info("Outreach queue: {$r['sent']} sent, {$r['dropped']} dropped, {$r['failed']} failed.");
        }

        return self::SUCCESS;
    }
}
