<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate accounts (JELD-WEN, LB Foster, Forged Solutions Group).
 *
 * corporate_accounts — billing entity with cost-code enforcement and VAT detail.
 * corporate_contacts — named booking contacts (e.g. Jackie Donoghue, Claire Green).
 * corporate_account_user — pivot granting a portal login to an account so a
 *   corporate_client user can book directly (Phase 4 portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('account_code', 32)->unique()->comment('Internal short code used on invoices');
            $table->string('billing_email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->boolean('cost_code_required')->default(true)
                ->comment('Mandatory cost code on every booking for this account');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('corporate_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporate_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('job_title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('corporate_account_id');
        });

        Schema::create('corporate_account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporate_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_view_all_account_bookings')->default(false);
            $table->timestamps();

            $table->unique(['corporate_account_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_account_user');
        Schema::dropIfExists('corporate_contacts');
        Schema::dropIfExists('corporate_accounts');
    }
};
