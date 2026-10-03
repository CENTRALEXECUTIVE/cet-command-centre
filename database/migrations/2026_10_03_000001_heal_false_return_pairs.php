<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * One-off data heal: unlink bookings that ETO's a/b suffix wrongly paired as
 * outbound/return when they are two independent bookings on the same journey
 * (same pickup). Runs the cet:fix-false-returns command. Guarded so it never
 * touches the test database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        Artisan::call('cet:fix-false-returns');
    }

    public function down(): void
    {
        // Data heal — nothing to roll back.
    }
};
