<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPS tracking (Phase 3) — GDPR sensitive.
 *
 * driver_locations — a ping every 5 minutes while the driver is on an active
 *   job only. Retained a maximum of 90 days then automatically deleted
 *   (scheduled prune). `captured_at` is indexed to make pruning cheap.
 *
 * tracking_links — tokenised public link sent to the customer the moment the
 *   driver goes En Route, expiring after the journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Location is only tracked while on an active job');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('heading', 5, 2)->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->decimal('accuracy', 6, 2)->nullable();
            $table->timestamp('captured_at')->index()->comment('Pruned after 90 days for GDPR');

            $table->index(['booking_id', 'captured_at']);
            $table->index(['driver_id', 'captured_at']);
        });

        Schema::create('tracking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_links');
        Schema::dropIfExists('driver_locations');
    }
};
