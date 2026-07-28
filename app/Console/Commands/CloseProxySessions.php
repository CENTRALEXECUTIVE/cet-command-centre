<?php

namespace App\Console\Commands;

use App\Services\Telephony\TwilioProxyService;
use Illuminate\Console\Command;

/**
 * Number-masking session maintenance: OPEN the masked line for any job that has
 * just entered its "goes live" window (pickup minus the per-booking lead), and
 * CLOSE any session past its closes_at (drop-off + the per-booking grace) in
 * case a status webhook/transition was missed. Scheduled every minute so the
 * lead/close timings are honoured tightly; Twilio's own DateExpiry backs it up.
 */
class CloseProxySessions extends Command
{
    protected $signature = 'cet:close-proxy-sessions';

    protected $description = 'Open due + close expired number-masking sessions';

    public function handle(TwilioProxyService $proxy): int
    {
        $opened = $proxy->openDueSessions();
        $closed = $proxy->closeExpired();

        $this->info("Opened {$opened} due and closed {$closed} expired masking session(s).");

        return self::SUCCESS;
    }
}
