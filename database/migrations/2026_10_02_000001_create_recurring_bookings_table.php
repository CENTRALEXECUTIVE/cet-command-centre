<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing / recurring bookings — a template that auto-creates a real booking for
 * each upcoming occurrence (e.g. a weekly airport run). Generated a few days ahead
 * by cet:generate-recurring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency', 16)->default('weekly'); // daily | weekdays | weekly
            $table->unsignedTinyInteger('weekday')->nullable();  // 0=Sun..6=Sat (weekly)
            $table->string('pickup_time', 5)->default('09:00');  // HH:MM, UK local
            $table->string('pickup_address', 500);
            $table->string('pickup_postcode', 12)->nullable();
            $table->string('destination_address', 500);
            $table->string('destination_postcode', 12)->nullable();
            $table->unsignedSmallInteger('passengers')->default(1);
            $table->string('payment_method', 16)->default('cash');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('lead_days')->default(3); // create this many days ahead
            $table->date('last_generated_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_bookings');
    }
};
