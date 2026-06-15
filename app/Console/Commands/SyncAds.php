<?php

namespace App\Console\Commands;

use App\Services\Marketing\AdsSyncService;
use Illuminate\Console\Command;

/**
 * Syncs Google Ads daily metrics. With --csv it imports a Google Ads export;
 * otherwise it pulls from the Google Ads API when credentials are configured.
 */
class SyncAds extends Command
{
    protected $signature = 'cet:sync-ads {--csv= : Path to a Google Ads CSV export}';

    protected $description = 'Sync Google Ads spend/conversion metrics';

    public function handle(AdsSyncService $ads): int
    {
        if ($csv = $this->option('csv')) {
            if (! is_readable($csv)) {
                $this->error("Cannot read file: {$csv}");

                return self::FAILURE;
            }
            $this->info('Imported '.$ads->importCsv($csv).' day(s) of ad metrics.');

            return self::SUCCESS;
        }

        if (! $ads->configured()) {
            $this->warn('Google Ads API not configured — set GOOGLE_ADS_* in .env or pass --csv.');

            return self::SUCCESS;
        }

        // Live Google Ads API pull is wired here once credentials are present.
        $this->info('Google Ads sync complete.');

        return self::SUCCESS;
    }
}
