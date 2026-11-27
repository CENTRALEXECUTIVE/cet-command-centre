<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load (27 Nov 2026): prospects similar to two warm leads (Hawsons,
 * Gradconsult) from docs/growth/sales/batch-J-similar-to-warm-leads.csv. Emails taken
 * from each firm's own website (source saved). House-style drafts ending "Kind regards,".
 * Duplicates skipped; nothing is sent here — they join the normal Outreach queue.
 * No schema change. No-op in tests.
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
        $file = base_path('docs/growth/sales/batch-J-similar-to-warm-leads.csv');
        if (is_file($file)) {
            $stats = app(ProspectImporter::class)->import($file, $owner?->id, $owner?->id);
            Log::info('Outreach prospects loaded', ['file' => 'batch-J'] + array_diff_key($stats, ['errors' => 1]));
        }
    }

    public function down(): void
    {
    }
};
