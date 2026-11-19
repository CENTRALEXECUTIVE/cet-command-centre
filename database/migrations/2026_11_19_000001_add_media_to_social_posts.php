<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Social posts carry their own photos/videos (a list of media keys). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_posts') && ! Schema::hasColumn('social_posts', 'media')) {
            Schema::table('social_posts', function (Blueprint $t) {
                $t->json('media')->nullable()->after('media_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_posts', 'media')) {
            Schema::table('social_posts', fn (Blueprint $t) => $t->dropColumn('media'));
        }
    }
};
