<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a GPS ping to attach to just a booking (no user account) so cover
 * drivers reached only by the shareable job link can still be tracked. The
 * driver_id becomes nullable; the ping is keyed to booking_id either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) can't easily ALTER a FK column; it's permissive about
        // nulls anyway, so only the real MySQL schema needs the change.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('driver_locations', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left in place — reverting to NOT NULL would drop driverless pings.
    }
};
