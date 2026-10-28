<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Console\Command;

/**
 * Import an Outreach prospects CSV on the server — same rules as the /outreach
 * upload (duplicates skipped, status/notes never overwritten, do-not-contact
 * never re-opened). Read-only on the file; sends nothing.
 *
 *   php artisan cet:import-prospects docs/growth/sales/2026-10-28-prospects.csv --owner=maj@…
 */
class ImportProspects extends Command
{
    protected $signature = 'cet:import-prospects {path : CSV file to import}
        {--owner= : Owner for NEW prospects (user email or id); default unassigned}';

    protected $description = 'Import B2B outreach prospects from a CSV (dedupes by company + town, respects do-not-contact)';

    public function handle(ProspectImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or not readable: {$path}");

            return self::FAILURE;
        }

        $ownerId = null;
        if ($opt = $this->option('owner')) {
            $owner = ctype_digit((string) $opt)
                ? User::find((int) $opt)
                : User::where('email', $opt)->first();
            if (! $owner || ! $owner->isAdmin()) {
                $this->error("No admin user found for --owner={$opt}");

                return self::FAILURE;
            }
            $ownerId = $owner->id;
        }

        $s = $importer->import($path, $ownerId);

        $this->info(self::summary($s));
        foreach ($s['errors'] as $e) {
            $this->warn($e);
        }

        return self::SUCCESS;
    }

    public static function summary(array $s): string
    {
        return "Prospects: {$s['created']} added, {$s['duplicates']} duplicate(s) skipped"
            .($s['filled'] ? " ({$s['filled']} had blank fields filled)" : '')
            .", {$s['dnc_skipped']} do-not-contact skipped"
            .($s['invalid'] ? ", {$s['invalid']} invalid row(s)" : '').'.';
    }
}
