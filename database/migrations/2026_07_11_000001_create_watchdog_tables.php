<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status watchdog storage:
 *  - job_nudges: every nudge push sent to a driver/admin about a job. The
 *    watchdog consults this before sending — it is the idempotency mechanism
 *    (max 2 sends per nudge type per job).
 *  - watchdog_events: the control-tower log behind the dashboard alerts feed —
 *    every decision worth showing (nudge sent, detection observed, escalation,
 *    status change, calendar import, GPS loss). Pruned after 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_nudges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('nudge_type', 40);
            $table->string('recipient_type', 12)->default('driver'); // driver | admin
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->string('channel', 12)->default('push');
            $table->timestamp('created_at')->nullable();

            $table->index(['booking_id', 'nudge_type', 'recipient_type']);
        });

        Schema::create('watchdog_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('severity', 12)->default('info'); // info | warning | critical
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['occurred_at']);
            $table->index(['severity', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchdog_events');
        Schema::dropIfExists('job_nudges');
    }
};
