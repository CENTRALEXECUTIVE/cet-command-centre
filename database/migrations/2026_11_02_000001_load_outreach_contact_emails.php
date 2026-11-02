<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load, applied by auto-deploy's `migrate --force`. Fills the
 * published contact email (and, where one was found, a named contact) from
 * docs/growth/sales/contacts.csv into EXISTING prospects only, matched by
 * company + town. Fill-only: blank fields only, nothing created, status/notes/
 * owner/dates never touched, do-not-contact never touched, nothing sent. The
 * email's source URL is written to each prospect's history. No schema change.
 * Guarded to a no-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        $path = base_path('docs/growth/sales/contacts.csv');
        if (! is_file($path)) {
            return;
        }

        $owner = User::where('email', 'admin@centralexecutivetransfers.co.uk')->first();
        if (! $owner || ! $owner->isAdmin()) {
            $owner = User::where('is_super_admin', true)->orderBy('id')->first();
        }

        $stats = app(ProspectImporter::class)->import($path, $owner?->id, $owner?->id, fillOnly: true);
        Log::info('Outreach contact emails loaded', array_diff_key($stats, ['errors' => 1]) + ['errors' => array_slice($stats['errors'], 0, 20)]);
    }

    public function down(): void
    {
        // Data load only.
    }
};
