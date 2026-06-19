<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hard guarantee against duplicate ETO bookings: a unique index on
 * (source_system, external_reference). Even if two processes (e.g. the email
 * scan and a CSV import) try to create the same reference at the same time, the
 * database rejects the second one — so a booking reference can exist only once.
 *
 * MySQL allows multiple NULLs in a unique index, so non-ETO bookings (which have
 * no external_reference) are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique(['source_system', 'external_reference'], 'bookings_source_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_source_ref_unique');
        });
    }
};
