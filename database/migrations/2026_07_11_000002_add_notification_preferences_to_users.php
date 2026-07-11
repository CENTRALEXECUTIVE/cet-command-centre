<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-admin alert preferences (JSON): toggles per watchdog event type, a
 * "critical only" master switch, and the optional new-critical chime.
 * Null = sensible defaults (everything on, chime off).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'notification_preferences')) {
            return; // already present (legacy-DB drift safety)
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
