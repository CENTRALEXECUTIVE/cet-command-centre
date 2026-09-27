<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the minibus classes so customers see clearer names on the booking page:
 * "Minibus 8 Seater" → "Standard Minibus", "Minibus 8 XL" → "Standard Minibus XL".
 * Idempotent (keyed by slug) so it's safe to re-run, and it runs on auto-deploy's
 * `migrate` so no manual seed is needed. Capacities are left untouched so any
 * office edit to them is preserved. (Subtitles are code defaults, not stored.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tests build their fleet from the seeder, which already has the new names.
        if (app()->environment('testing')) {
            return;
        }

        DB::table('vehicle_types')->where('slug', 'minibus-8')->update(['name' => 'Standard Minibus']);
        DB::table('vehicle_types')->where('slug', 'minibus-8-xl')->update(['name' => 'Standard Minibus XL']);
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        DB::table('vehicle_types')->where('slug', 'minibus-8')->update(['name' => 'Minibus 8 Seater']);
        DB::table('vehicle_types')->where('slug', 'minibus-8-xl')->update(['name' => 'Minibus 8 XL']);
    }
};
