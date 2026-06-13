<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet & driver compliance tracker (Phase 3).
 *
 * A single polymorphic-style table of renewable items (MOT, insurance, PHV
 * vehicle licence, compliance test, PHV badge, DBS) tied to either a vehicle or
 * a driver. Drives the automated WhatsApp renewal reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_items', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 16)->comment('vehicle | driver');
            $table->unsignedBigInteger('subject_id');
            $table->string('item_type', 32)->comment('mot | insurance | phv_vehicle | compliance_test | phv_badge | dbs');
            $table->string('reference')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on');
            $table->string('status', 16)->default('valid')->comment('valid | due_soon | expired');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['item_type', 'due_on']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_items');
    }
};
