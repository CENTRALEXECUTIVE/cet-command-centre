<?php

use App\Models\CoverDriver;
use App\Services\DriverRosterService;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-run the roster reconcile now that matching includes admin/director accounts
 * that also drive — so "Abdi"/"Majid" fold into the real Abdirazak/Majid Ali
 * accounts (keeping their admin access) and the duplicate driver accounts are
 * removed. Skipped under testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! class_exists(CoverDriver::class)) {
            return;
        }

        $roster = app(DriverRosterService::class);
        foreach (CoverDriver::all() as $driver) {
            $roster->ensureUser($driver);
        }
        $roster->pruneOrphanSyntheticDrivers();
    }

    public function down(): void
    {
        // no-op
    }
};
