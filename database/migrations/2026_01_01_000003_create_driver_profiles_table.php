<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver profile — compliance and licensing detail for users with the
 * driver role (Abdi, Maj and any third-party drivers). Feeds the Phase 3
 * fleet & compliance tracker (PHV badge, DBS status, renewals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_third_party')->default(false);
            $table->string('phv_badge_number', 64)->nullable();
            $table->date('phv_badge_expiry')->nullable();
            $table->string('dbs_status', 32)->nullable()->comment('clear | pending | expired');
            $table->date('dbs_issue_date')->nullable();
            $table->date('dbs_expiry')->nullable();
            $table->string('driving_licence_number', 64)->nullable();
            $table->date('driving_licence_expiry')->nullable();
            // Default assigned vehicle (a driver may rotate vehicles too)
            $table->foreignId('default_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
