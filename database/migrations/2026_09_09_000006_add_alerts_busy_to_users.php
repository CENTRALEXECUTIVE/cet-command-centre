<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A director can flag themselves "busy — hold my alerts" (e.g. out on a cover job
 * the system can't see). While this timestamp is in the future, emergency alerts
 * route to the OTHER director instead. Auto-expires so it can never be left on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('alerts_busy_until')->nullable()->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alerts_busy_until');
        });
    }
};
