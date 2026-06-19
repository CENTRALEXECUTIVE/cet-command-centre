<?php

namespace App\Console\Commands;

use App\Services\Inbox\OutlookBookingService;
use Illuminate\Console\Command;

/**
 * Reads unread Outlook booking emails and creates bookings from them via the AI
 * parser. No-op until Microsoft Graph credentials are configured. Scheduled
 * every few minutes.
 */
class IngestOutlook extends Command
{
    protected $signature = 'cet:ingest-outlook {--days=30 : days of inbox history to scan} {--no-rotate : do not auto-assign rotation (for bulk backfill)}';

    protected $description = 'Parse Outlook booking emails into bookings';

    public function handle(OutlookBookingService $outlook): int
    {
        $stats = $outlook->ingest((int) $this->option('days'), ! $this->option('no-rotate'));
        $this->info("Processed {$stats['processed']} — created {$stats['created']}, updated {$stats['updated']}, "
            ."cancelled {$stats['cancelled']}, skipped {$stats['skipped']}.");

        return self::SUCCESS;
    }
}
