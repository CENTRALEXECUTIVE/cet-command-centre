<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load, applied by auto-deploy's `migrate --force`: adds
 * B. Braun (Sheffield UK head office) from
 * docs/growth/sales/batch-H-direct.csv. B. Braun gave the directors the
 * customer care address themselves. Same importer as the other loads:
 * duplicates skipped, do-not-contact never re-opened, nothing sent.
 * No schema change. Guarded to a no-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        $owner = User::where('email', 'admin@centralexecutivetransfers.co.uk')->first();
        if (! $owner || ! $owner->isAdmin()) {
            $owner = User::where('is_super_admin', true)->orderBy('id')->first();
        }

        $path = base_path('docs/growth/sales/batch-H-direct.csv');
        if (is_file($path)) {
            $stats = app(ProspectImporter::class)->import($path, $owner?->id, $owner?->id);
            Log::info('Outreach prospects loaded', ['file' => 'batch-H-direct.csv'] + array_diff_key($stats, ['errors' => 1]));
        }
    }

    public function down(): void
    {
        // Data load only; prospects are kept.
    }
};
