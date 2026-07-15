<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable home for the per-job "masking off" switch. It lived in booking.meta,
 * but meta is rewritten by several flows (calendar mirror, edits, watchdog), so
 * a dedicated column guarantees that once the office unmasks a job it STAYS
 * unmasked until they re-mask it. Nothing in the calendar sync touches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings') || Schema::hasColumn('bookings', 'masking_disabled')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('masking_disabled')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'masking_disabled')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('masking_disabled');
            });
        }
    }
};
