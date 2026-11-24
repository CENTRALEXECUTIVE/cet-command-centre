<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI phone agent (directors, 23 Nov 2026): one row per call the agent answers.
 * The transcript, any quote given and any booking request taken. Nothing here
 * creates a booking: the office reviews and confirms it through Paste a booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('phone_calls')) {
            return;
        }
        Schema::create('phone_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_sid', 64)->unique();
            $table->string('from_number', 32)->nullable();
            $table->string('status', 24)->default('active'); // active, completed, transferred, message
            $table->json('transcript')->nullable();
            $table->json('booking_fields')->nullable();
            $table->json('quotes')->nullable();
            $table->text('message')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('needs_action')->default(false);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['needs_action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_calls');
    }
};
