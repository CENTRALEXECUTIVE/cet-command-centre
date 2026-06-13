<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads dashboard (Phase 4).
 *
 * Daily snapshot of spend vs revenue with live ROAS and the trigger thresholds
 * (40 conversions, 100 jobs, £14,000 revenue) for budget alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('campaign')->nullable();
            $table->decimal('spend', 10, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedInteger('jobs')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('roas', 8, 2)->nullable()->comment('revenue / spend');
            $table->boolean('budget_alert_triggered')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_metrics');
    }
};
