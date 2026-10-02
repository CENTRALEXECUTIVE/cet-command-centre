<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly availability per driver — which days they normally work, plus a free note
 * (e.g. "mornings only"). Beyond the simple on/off is_available flag, so the office
 * can see who's normally working on a given day when allocating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->json('available_days')->nullable()->after('is_available');
            $table->string('availability_note', 255)->nullable()->after('available_days');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['available_days', 'availability_note']);
        });
    }
};
