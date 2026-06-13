<?php

namespace App\Console\Commands;

use App\Services\ComplianceService;
use Illuminate\Console\Command;

/**
 * Syncs fleet/driver compliance dates, reclassifies each item, and sends
 * automated WhatsApp renewal reminders for anything due soon or expired.
 * Scheduled daily.
 */
class CheckCompliance extends Command
{
    protected $signature = 'cet:check-compliance';

    protected $description = 'Sync compliance items and send renewal reminders';

    public function handle(ComplianceService $compliance): int
    {
        $synced = $compliance->sync();
        $reminders = $compliance->sendDueReminders();

        $this->info("Synced {$synced} compliance item(s); sent {$reminders} reminder(s).");

        return self::SUCCESS;
    }
}
