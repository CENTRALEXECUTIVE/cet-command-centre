<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet definitions.
 *
 * vehicle_types — the bookable classes (Executive, Estate, V Class, Minibus,
 *   Minibus XL, Rolls Royce Ghost). The `affects_rotation` flag is critical:
 *   only the Executive saloon drives the Abdi/Maj rotation. All other classes
 *   are excluded from rotation per the company rules.
 *
 * vehicles — the physical vehicles with compliance dates feeding the
 *   fleet & compliance tracker (Phase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('passenger_capacity')->default(4);
            $table->unsignedSmallInteger('luggage_capacity')->default(2);
            $table->boolean('affects_rotation')->default(false)
                ->comment('Only the Executive saloon affects the Abdi/Maj rotation');
            $table->boolean('uses_third_party_driver')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_type_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('registration', 16)->unique();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('colour', 32)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            // Compliance tracking (Phase 3 fleet & compliance tracker)
            $table->date('mot_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->date('phv_licence_expiry')->nullable();
            $table->date('compliance_test_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('vehicle_types');
    }
};
