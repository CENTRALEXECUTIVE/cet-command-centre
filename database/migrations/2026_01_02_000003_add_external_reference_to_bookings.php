<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the originating external system reference (e.g. the ETO booking
 * reference) so historical imports can be de-duplicated and matched one-to-one
 * during the parallel run with ETO before cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source_system', 32)->nullable()->after('source');
            $table->string('external_reference', 64)->nullable()->after('source_system');
            $table->index('external_reference');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['external_reference']);
            $table->dropColumn(['source_system', 'external_reference']);
        });
    }
};
