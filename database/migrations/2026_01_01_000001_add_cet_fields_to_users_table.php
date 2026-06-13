<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the default users table with CET-specific fields:
 * role-based access (admin, driver, corporate_client), contact details,
 * activation status and last login tracking for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('driver')->after('email')
                ->comment('admin | driver | corporate_client');
            $table->string('phone', 32)->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('phone');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->softDeletes();

            $table->index('role');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'phone', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
