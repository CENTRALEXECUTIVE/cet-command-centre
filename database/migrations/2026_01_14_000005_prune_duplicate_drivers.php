<?php

use App\Models\CoverDriver;
use App\Services\DriverRosterService;
use Illuminate\Database\Migrations\Migration;

/**
 * Final reconcile + prune of the driver roster: re-attach directory entries to
 * the real rotation drivers and remove any leftover duplicate throwaway accounts
 * a previous sync orphaned. Runs as its own migration so it reaches servers that
 * already applied the earlier reconcile. Skipped under testing.
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
