<?php

use App\Services\Social\SocialDecemberPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** One-off (directors, 19 Nov 2026): load the 2–15 Dec Christmas/New Year posts, photos attached. */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasColumn('social_posts', 'media')) {
            return;
        }
        app(SocialDecemberPlan::class)->load();
    }

    public function down(): void {}
};
