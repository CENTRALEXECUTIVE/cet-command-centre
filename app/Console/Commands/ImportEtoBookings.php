<?php

namespace App\Console\Commands;

use App\Services\Import\EtoBookingImporter;
use Illuminate\Console\Command;

/**
 * Imports historical bookings from an ETO CSV export. Use --dry-run to validate
 * the file (and report duplicates) without writing anything.
 */
class ImportEtoBookings extends Command
{
    protected $signature = 'cet:import-eto-bookings {path : Path to the ETO bookings CSV} {--dry-run : Validate without writing}';

    protected $description = 'Import historical bookings from an ETO CSV export';

    public function handle(EtoBookingImporter $importer): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = $importer->import($path, $dryRun);

        // Record a real import so the monthly-review reminder clears.
        if (! $dryRun) {
            \App\Models\Setting::set('last_eto_import_at', now()->toDateTimeString(), 'string', 'eto');
        }

        $this->table(['Outcome', 'Count'], [
            [$dryRun ? 'Would create (new)' : 'Created (new)', $stats['imported']],
            [$dryRun ? 'Would update (existing)' : 'Updated (existing financials)', $stats['updated'] ?? 0],
            ['Skipped (quotes/other)', $stats['skipped']],
            ['Errors', count($stats['errors'])],
        ]);

        foreach (array_slice($stats['errors'], 0, 20) as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
