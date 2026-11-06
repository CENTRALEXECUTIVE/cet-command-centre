<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load (contact research pass 3, 6 Nov 2026), applied by
 * auto-deploy's `migrate --force`. docs/growth/sales/contacts.csv now carries
 * 65 more PUBLISHED email addresses (each with its source URL) and published
 * phone numbers. Re-read in FILL-ONLY mode: only blank fields are filled,
 * nothing is created, an address or name a director already set is never
 * overwritten, do-not-contact is never touched, nothing is sent.
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

        $path = base_path('docs/growth/sales/contacts.csv');
        if (is_file($path)) {
            $stats = app(ProspectImporter::class)->import($path, $owner?->id, $owner?->id, fillOnly: true);
            Log::info('Outreach contact emails loaded (pass 3)', array_diff_key($stats, ['errors' => 1]) + ['errors' => array_slice($stats['errors'], 0, 20)]);
        }
    }

    public function down(): void
    {
        // Data load only.
    }
};
