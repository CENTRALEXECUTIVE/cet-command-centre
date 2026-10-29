<?php

use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data refresh, applied by auto-deploy's `migrate --force`. The first
 * emails in docs/growth/sales were rewritten to the house style
 * (docs/growth/sales/kit/email-style-guide.md). This replaces the drafted
 * subject + body ONLY on prospects still 'new' (never emailed) whose email the
 * directors haven't edited by hand. Status, notes, owner and dates are never
 * touched; do-not-contact is never touched. Guarded to a no-op in tests.
 */
return new class extends Migration
{
    private const FILES = [
        '2026-10-28-prospects.csv',
        'batch-A-manufacturing.csv',
        'batch-B-professional.csv',
        'batch-C-logistics-national.csv',
        'batch-D-partners.csv',
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
            $stats = $importer->import($path, $owner?->id, $owner?->id, refreshDrafts: true);
            Log::info('Outreach drafts refreshed', ['file' => $file] + array_diff_key($stats, ['errors' => 1]));
        }
    }

    public function down(): void
    {
        // Data refresh only.
    }
};
