<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated, indexed column for the shareable driver-link token. Previously
 * the token lived in the meta JSON and was looked up with a JSON-path query,
 * which is slower and can miss — a link that 404s for some drivers. An indexed
 * string column resolves every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'driver_link_token')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('driver_link_token', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'driver_link_token')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('driver_link_token');
            });
        }
    }
};
