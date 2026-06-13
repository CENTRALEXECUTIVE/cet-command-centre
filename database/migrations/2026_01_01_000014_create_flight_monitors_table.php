<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flight delay monitoring (Phase 5).
 *
 * For airport pickups with a flight number, polls a flight API to auto-detect
 * delays, adjusts the pickup time and notifies the customer automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('flight_number', 32);
            $table->date('flight_date')->nullable();
            $table->dateTime('scheduled_arrival')->nullable();
            $table->dateTime('estimated_arrival')->nullable();
            $table->string('status', 24)->nullable()->comment('scheduled | active | landed | delayed | cancelled | diverted');
            $table->integer('delay_minutes')->default(0);
            $table->boolean('pickup_adjusted')->default(false);
            $table->boolean('customer_notified')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index('flight_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_monitors');
    }
};
