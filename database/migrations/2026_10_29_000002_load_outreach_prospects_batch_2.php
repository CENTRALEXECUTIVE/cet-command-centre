<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load (second round), applied by auto-deploy's `migrate --force`.
 * Re-imports the first list so its newly drafted emails fill the blank email
 * fields on the existing prospects, then adds the new rehab/medical,
 * Barnsley/Doncaster/region and international-partner lists. Same importer as
 * the first load: duplicates skipped, existing status/notes/owner untouched,
 * do-not-contact never re-opened. Guarded to a no-op in tests.
 */
return new class extends Migration
{
    private const FILES = [
        '2026-10-28-prospects.csv',
        'batch-E-rehab-medical.csv',
        'batch-F-barnsley-doncaster-region.csv',
        'batch-G-international-partners.csv',
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
