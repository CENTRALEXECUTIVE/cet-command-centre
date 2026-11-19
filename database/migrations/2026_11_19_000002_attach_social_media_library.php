<?php

use App\Services\Social\SocialMediaLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One-off (directors, 19 Nov 2026: "every post with their photos waiting"):
 * attach the finished photos/videos to the planned posts, mark them Ready,
 * and add the six stories for WhatsApp Status / Instagram / Facebook.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasColumn('social_posts', 'media')) {
            return;
        }
        app(SocialMediaLibrary::class)->apply();
    }

    public function down(): void {}
};
