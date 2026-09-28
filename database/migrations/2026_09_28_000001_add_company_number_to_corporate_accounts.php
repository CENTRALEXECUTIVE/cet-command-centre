<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Companies House number for a corporate account (captured on the public
// "Open a business account" sign-up). Nullable — not every account has one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('corporate_accounts', 'company_number')) {
                $table->string('company_number', 32)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('corporate_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('corporate_accounts', 'company_number')) {
                $table->dropColumn('company_number');
            }
        });
    }
};
