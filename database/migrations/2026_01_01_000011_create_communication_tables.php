<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer communications & engagement.
 *
 * messages — unified outbound/inbound log across channels (WhatsApp, SMS,
 *   email, push). Covers booking confirmations, 24h/2h reminders, En Route
 *   tracking links and the 30-minutes-after-completion review request.
 *
 * waiting_list_entries — auto-notify a customer when a cancellation frees
 *   availability (Phase 5).
 *
 * missed_calls — every missed call gets an instant auto WhatsApp response so
 *   no booking is ever lost (Phase 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16)->comment('whatsapp | sms | email | push');
            $table->string('direction', 8)->default('outbound')->comment('outbound | inbound');
            $table->string('type', 32)->comment('confirmation | reminder_24h | reminder_2h | tracking_link | review_request | waiting_list | custom');
            $table->string('to_address')->nullable()->comment('Phone or email');
            $table->text('body')->nullable();
            $table->string('status', 16)->default('queued')->comment('queued | sent | delivered | read | failed');
            $table->string('provider_message_id')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index(['type', 'scheduled_for']);
        });

        Schema::create('waiting_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('desired_date');
            $table->string('desired_time_window')->nullable();
            $table->text('pickup_address')->nullable();
            $table->text('destination_address')->nullable();
            $table->string('status', 16)->default('waiting')->comment('waiting | notified | converted | expired');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'desired_date']);
        });

        Schema::create('missed_calls', function (Blueprint $table) {
            $table->id();
            $table->string('from_number', 32);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->boolean('auto_response_sent')->default(false);
            $table->timestamp('auto_response_sent_at')->nullable();
            $table->boolean('called_back')->default(false);
            $table->timestamps();

            $table->index('from_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_calls');
        Schema::dropIfExists('waiting_list_entries');
        Schema::dropIfExists('messages');
    }
};
