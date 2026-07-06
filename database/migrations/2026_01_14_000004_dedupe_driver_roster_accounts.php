<?php

use App\Models\CoverDriver;
use App\Services\DriverRosterService;
use Illuminate\Database\Migrations\Migration;

/**
 * Reconcile the roster on deploy: merge throwaway "Abdi"/"Majid" accounts (made
 * by an earlier sync before de-dup existed) into the matching rotation drivers
 * and remove the duplicates, so each driver shows once. Skipped under testing.
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
    }

    public function down(): void
    {
        // no-op
    }
};
