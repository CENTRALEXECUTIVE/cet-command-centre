<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver rotation engine state.
 *
 * airports — reference list (HUY, EMA, STN, LGW, LPL, LHR, MAN, LBA, BHX, ...)
 *   plus the special "Free Roam" / general pool.
 *
 * rotation_states — the live "who is next" pointer per airport, per
 *   rotation-affecting vehicle type (the Executive saloon). When an airport is
 *   not yet present, the engine defaults the next driver to ABDI per company rule.
 *
 * rotation_logs — immutable history of every rotation advance, recording which
 *   booking moved the pointer (and whether a substitution preserved position).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique()->comment('IATA code or FREE_ROAM');
            $table->string('name');
            $table->boolean('is_general_pool')->default(false)
                ->comment('Free Roam / catch-all for non-airport work');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('rotation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('next_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_advanced_at')->nullable();
            $table->timestamps();

            $table->unique(['airport_id', 'vehicle_type_id']);
        });

        Schema::create('rotation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable();
            $table->foreignId('from_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable()
                ->comment('advance | paired_outbound | substitution_no_advance | manual_override');
            $table->timestamps();

            $table->index(['airport_id', 'vehicle_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_logs');
        Schema::dropIfExists('rotation_states');
        Schema::dropIfExists('airports');
    }
};
