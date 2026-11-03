<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional CC on an outreach prospect (e.g. production@ + the partnerships director on CC). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prospects') || Schema::hasColumn('prospects', 'cc_email')) {
            return;
        }
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('cc_email', 255)->nullable()->after('contact_email');
            $table->string('cc_name', 200)->nullable()->after('cc_email');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospects', 'cc_email')) {
            Schema::table('prospects', fn (Blueprint $t) => $t->dropColumn(['cc_email', 'cc_name']));
        }
    }
};
