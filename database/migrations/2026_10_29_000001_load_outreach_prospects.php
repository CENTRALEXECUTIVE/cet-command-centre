<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load, applied automatically by auto-deploy's `migrate --force`
 * so the directors don't have to upload the CSVs by hand. Imports the five
 * researched prospect lists in docs/growth/sales into Outreach, owned by the
 * admin@ director account (else the first super admin). The importer skips
 * duplicates (same company + town) and never re-opens do-not-contact
 * prospects, so this is safe to re-run. Business contacts only — no customer
 * data. Guarded to a no-op in tests.
 */
return new class extends Migration
{
    private const FILES = [
        'batch-A-manufacturing.csv',
        '2026-10-28-prospects.csv',
        'batch-B-professional.csv',
        'batch-C-logistics-national.csv',
        'batch-D-partners.csv',
    ];

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        $owner = User::where('email', 'admin@centralexecutivetransfers.co.uk')->first();
        if (! $owner || ! $owner->isAdmin()) {
            $owner = User::where('is_super_admin', true)->orderBy('id')->first();
        }

        $importer = app(ProspectImporter::class);
        foreach (self::FILES as $file) {
            $path = base_path('docs/growth/sales/'.$file);
            if (! is_file($path)) {
                continue;
            }
            $stats = $importer->import($path, $owner?->id, $owner?->id);
            Log::info('Outreach prospects loaded', ['file' => $file] + array_diff_key($stats, ['errors' => 1]));
        }
    }

    public function down(): void
    {
        // Data load only; prospects are kept.
    }
};
