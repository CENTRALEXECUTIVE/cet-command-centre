<?php

use App\Models\CoverDriver;
use App\Services\DriverRosterService;
use Illuminate\Database\Migrations\Migration;

/**
 * Create the assignable driver accounts for the seeded roster on deploy, so the
 * directory drivers appear on the dispatch board without a manual step. Skipped
 * under testing so RefreshDatabase runs aren't seeded with roster accounts.
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
            $driver->phone = $roster->normalizePhone($driver->phone);
            $driver->save();
            $roster->ensureUser($driver);
        }
    }

    public function down(): void
    {
        // Accounts are intentionally kept (they may be on past jobs).
    }
};
