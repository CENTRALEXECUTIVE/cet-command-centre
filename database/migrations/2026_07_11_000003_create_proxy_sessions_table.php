<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Twilio Proxy number masking:
 *  - proxy_sessions: one row per masked customer↔driver conversation, tied to
 *    a booking. masked_number is what the DRIVER dials/sees; the customer has
 *    their own masked line (customer_masked_number). Auto-closes at closes_at.
 *  - proxy_events: the audit trail — session opens/closes, call and message
 *    events from Twilio's callbacks. Purged on the GPS retention schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('twilio_session_sid', 64)->nullable();
            $table->string('customer_participant_sid', 64)->nullable();
            $table->string('driver_participant_sid', 64)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('masked_number', 24)->nullable();          // driver-facing line
            $table->string('customer_masked_number', 24)->nullable(); // customer-facing line
            $table->string('status', 16)->default('open');            // open | closed | failed
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['status', 'closes_at']);
        });

        Schema::create('proxy_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['booking_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_events');
        Schema::dropIfExists('proxy_sessions');
    }
};
