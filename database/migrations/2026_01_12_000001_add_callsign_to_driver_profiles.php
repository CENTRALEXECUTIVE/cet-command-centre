<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit, operator-controlled display name (callsign) for a driver — the
 * short name shown to customers on WhatsApp reminders / driver details and on
 * the calendar tag (e.g. "Abdi", "Kash", "Maj"), independent of their login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('callsign', 40)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('callsign');
        });
    }
};
