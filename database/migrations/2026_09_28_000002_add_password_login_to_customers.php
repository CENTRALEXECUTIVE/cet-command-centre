<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional self-service password login for CUSTOMERS on the "My Account" widget.
// This is entirely separate from the staff (users table) login — a customer never
// becomes a system user and never reaches the admin/dispatch app. All columns are
// nullable: existing customers simply have no password until they set one, and the
// account page still works by booking reference + contact when there's no password.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'password')) {
                $table->string('password')->nullable()->after('email');
            }
            if (! Schema::hasColumn('customers', 'password_reset_token')) {
                $table->string('password_reset_token', 64)->nullable()->after('password');
            }
            if (! Schema::hasColumn('customers', 'password_reset_expires_at')) {
                $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['password', 'password_reset_token', 'password_reset_expires_at'] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
