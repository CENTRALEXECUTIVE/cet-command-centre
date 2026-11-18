<?php

use App\Services\Social\SocialPlanImport;
use App\Services\Social\SocialStarterPack;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One-off (directors, 18 Nov 2026: "yes do it"): fill the Social Media Command
 * Centre with the full prepared plan (31 posts + wedding Reel + ideas), moved
 * to start tomorrow. Skipped if the plan was already loaded by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('social_posts')) {
            return;
        }
        $import = app(SocialPlanImport::class);
        if (! $import->available()) {
            return;
        }
        // Already loaded the full plan (by hand)? Leave it.
        if (\App\Models\SocialPost::where('notes', 'like', SocialStarterPack::MARKER.' (prepared plan%')->exists()) {
            return;
        }
        $import->load(); // first post tomorrow
    }

    public function down(): void {}
};
