<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Governance, GDPR & configuration (non-negotiable requirements).
 *
 * audit_logs — complete, immutable record of every data access and change:
 *   who, what, before/after values, IP and user agent.
 * consents — privacy/cookie/marketing consent captured at every collection point.
 * data_erasure_requests — right-to-erasure workflow; the system can delete all
 *   customer data on request.
 * settings — keyed configuration (ICO registration number, calendar id,
 *   notification offsets, feature flags) so secrets stay in .env and operational
 *   config stays editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32)->comment('created | updated | deleted | viewed | login | logout | exported | erased');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 16)->default('customer')->comment('customer | user');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('email')->nullable()->comment('Captured even before a customer record exists');
            $table->string('type', 32)->comment('privacy_notice | cookies | marketing | data_processing');
            $table->boolean('granted')->default(false);
            $table->string('version', 16)->nullable()->comment('Policy version consented to');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('type');
        });

        Schema::create('data_erasure_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requested_by_email')->nullable();
            $table->string('status', 16)->default('pending')->comment('pending | approved | completed | rejected');
            $table->text('reason')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->json('summary')->nullable()->comment('What was erased/anonymised');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group', 32)->default('general');
            $table->string('type', 16)->default('string')->comment('string | int | bool | json');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('data_erasure_requests');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('audit_logs');
    }
};
