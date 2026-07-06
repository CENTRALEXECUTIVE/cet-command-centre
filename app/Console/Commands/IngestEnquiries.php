<?php

namespace App\Console\Commands;

use App\Services\Inbox\EnquiryInboxService;
use Illuminate\Console\Command;

/**
 * Pulls customer enquiry emails from Outlook, extracts the journey with the AI,
 * prepares a quote and drafts a reply for the operator to review. No-op until
 * Microsoft Graph + Anthropic are configured.
 */
class IngestEnquiries extends Command
{
    protected $signature = 'cet:ingest-enquiries {--days=14 : days of inbox history to scan}';

    protected $description = 'Turn Outlook customer enquiries into reviewable quotes + draft replies';

    public function handle(EnquiryInboxService $enquiries): int
    {
        $stats = $enquiries->ingest((int) $this->option('days'));
        $this->info("Processed {$stats['processed']} — created {$stats['created']}, skipped {$stats['skipped']}.");

        return self::SUCCESS;
    }
}
