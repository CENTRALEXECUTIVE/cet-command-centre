<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load (6 Nov 2026), applied by auto-deploy's `migrate --force`:
 *  1. Adds 30 new prospects from docs/growth/sales/batch-I-professional-travellers.csv
 *     (owner-managed Sheffield/South Yorkshire firms whose directors travel, found after
 *     a Hawsons director said he flies from Heathrow 7-8 times a year). House-style
 *     drafts ending at "Kind regards,". Duplicates skipped.
 *  2. Re-reads docs/growth/sales/contacts.csv in FILL-ONLY mode: 16 more addresses
 *     published on the companies' own websites (source page saved). Blank fields only;
 *     a director's edits and do-not-contact are never touched. Nothing is sent.
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
        $importer = app(ProspectImporter::class);

        $batch = base_path('docs/growth/sales/batch-I-professional-travellers.csv');
        if (is_file($batch)) {
            $stats = $importer->import($batch, $owner?->id, $owner?->id);
            Log::info('Outreach prospects loaded', ['file' => 'batch-I'] + array_diff_key($stats, ['errors' => 1]));
        }

        $contacts = base_path('docs/growth/sales/contacts.csv');
        if (is_file($contacts)) {
            $stats = $importer->import($contacts, $owner?->id, $owner?->id, fillOnly: true);
            Log::info('Outreach contact emails loaded (pass 4)', array_diff_key($stats, ['errors' => 1]));
        }
    }

    public function down(): void
    {
        // Data load only.
    }
};
