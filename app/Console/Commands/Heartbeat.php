<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Stamps the global scheduler heartbeat every minute. If this stops updating, the
 * `schedule:run` cron is not running — the System Health page and the admin banner
 * use it to turn that silent failure into a visible one.
 */
class Heartbeat extends Command
{
    protected $signature = 'cet:heartbeat';

    protected $description = 'Stamp the scheduler heartbeat so health checks can see the cron is alive';

    public function handle(): int
    {
        \App\Support\Heartbeat::stamp('scheduler');

        return self::SUCCESS;
    }
}
