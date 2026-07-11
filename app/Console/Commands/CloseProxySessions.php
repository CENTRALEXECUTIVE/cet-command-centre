<?php

namespace App\Console\Commands;

use App\Services\Telephony\TwilioProxyService;
use Illuminate\Console\Command;

/**
 * Safety net for number masking: closes any Twilio Proxy session past its
 * closes_at (drop-off + 4h) in case a status webhook/transition was missed.
 * Scheduled every five minutes; Twilio's own DateExpiry backs this up.
 */
class CloseProxySessions extends Command
{
    protected $signature = 'cet:close-proxy-sessions';

    protected $description = 'Close expired number-masking sessions (drop-off + 4h)';

    public function handle(TwilioProxyService $proxy): int
    {
        $closed = $proxy->closeExpired();

        $this->info("Closed {$closed} expired masking session(s).");

        return self::SUCCESS;
    }
}
