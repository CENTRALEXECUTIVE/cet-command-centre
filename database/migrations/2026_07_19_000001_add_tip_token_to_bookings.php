<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated, indexed column for the customer tip-link token. The token used to
 * live only in the meta JSON and was looked up with a JSON-path query, which can
 * miss on some databases — a valid tip link then shows "Link not found". An
 * indexed string column resolves every time, exactly like driver_link_token.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'tip_token')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('tip_token', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'tip_token')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('tip_token');
            });
        }
    }
};
