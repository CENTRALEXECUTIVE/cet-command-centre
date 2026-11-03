<?php

use App\Models\User;
use App\Services\Outreach\ContactUpgrader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load: better published contacts (named buyers / department inboxes
 * + CC) from docs/growth/sales/contacts-upgrade.csv. Only never-emailed prospects
 * whose contact no director has edited. Nothing is sent. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasColumn('prospects', 'cc_email')) {
            return;
        }
        $path = base_path('docs/growth/sales/contacts-upgrade.csv');
        if (! is_file($path)) {
            return;
        }
        $owner = User::where('is_super_admin', true)->orderBy('id')->first();
        Log::info('Outreach contacts upgraded', app(ContactUpgrader::class)->import($path, $owner?->id));
    }

    public function down(): void
    {
        // Data load only.
    }
};
