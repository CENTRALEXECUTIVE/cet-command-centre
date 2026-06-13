<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Calendar mirror.
 *
 * Stores the formatted event pushed to admin@centralexecutivetransfers.co.uk:
 * bold title with vehicle/driver tag and payment emoji (💰 / 👀), pickup address
 * in the location field, start = pickup time and end = +1 hour. Keeps the
 * Google event id so edits and deletes stay in sync, and records which
 * notifications (2h email, 3h/7h/1d push, 3-day balance push) were scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('calendar_id')->default('admin@centralexecutivetransfers.co.uk');
            $table->string('google_event_id')->nullable()->index();
            $table->string('title');
            $table->text('location')->nullable()->comment('Pickup address');
            $table->dateTime('start_at');
            $table->dateTime('end_at')->comment('Always pickup time + 1 hour');
            $table->string('timezone', 32)->default('Europe/London')
                ->comment('BST +01:00 late Mar–Oct, UTC Nov–Mar');
            $table->string('payment_emoji', 8)->nullable()->comment('💰 cash | 👀 card balance');
            $table->json('notifications')->nullable()
                ->comment('Scheduled reminders: 2h email, 3h/7h/1d push, 3-day balance push');
            $table->string('sync_status', 16)->default('pending')->comment('pending | synced | failed | deleted');
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
