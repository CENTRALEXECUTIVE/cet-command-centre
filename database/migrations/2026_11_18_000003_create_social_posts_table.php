<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social Media Command Centre: one row per post (idea → planned → ready →
 * posted). The numbers (views, likes …) are typed in by the office from each
 * app's own insights, so no paid API is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_posts')) {
            return;
        }

        Schema::create('social_posts', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 20)->index();
            $t->string('status', 12)->default('idea')->index();
            $t->string('format', 40)->nullable();
            $t->string('pillar', 40)->nullable();
            $t->string('title', 160);
            $t->text('caption')->nullable();
            $t->string('hashtags', 500)->nullable();
            $t->text('media_note')->nullable();
            $t->dateTime('scheduled_at')->nullable()->index();
            $t->dateTime('posted_at')->nullable()->index();
            $t->string('url', 500)->nullable();
            $t->unsignedInteger('views')->nullable();
            $t->unsignedInteger('likes')->nullable();
            $t->unsignedInteger('comments')->nullable();
            $t->unsignedInteger('shares')->nullable();
            $t->unsignedInteger('saves')->nullable();
            $t->unsignedInteger('follows')->nullable();
            $t->unsignedInteger('enquiries')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
