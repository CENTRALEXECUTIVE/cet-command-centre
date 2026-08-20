<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "known as" nickname for a driver — how the OFFICE refers to them internally
 * (e.g. "Hamza E Class"), kept separate from their real name. Reminders and any
 * customer-facing message always use the real name; the nickname is only for the
 * office to recognise who a job/driver is on the Command Centre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('callsign');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
