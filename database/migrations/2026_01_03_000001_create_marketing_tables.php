<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing management: Google Ads keywords and SEO pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword');
            $table->string('match_type', 16)->default('phrase')->comment('broad | phrase | exact');
            $table->string('campaign')->nullable();
            $table->string('status', 16)->default('enabled')->comment('enabled | paused');
            $table->decimal('max_cpc', 8, 2)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('avg_position', 5, 1)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('path')->comment('e.g. /airport-transfers-sheffield');
            $table->string('title');
            $table->text('meta_description')->nullable();
            $table->string('target_keyword')->nullable();
            $table->unsignedInteger('current_rank')->nullable();
            $table->unsignedInteger('monthly_searches')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
        Schema::dropIfExists('keywords');
    }
};
