<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach — B2B prospects the directors email by hand from their own mailbox,
 * plus a per-prospect activity history. Prospects are NOT bookings or customers,
 * so they get their own tables. Nothing here sends anything.
 *
 * `dedupe_key` = lower(company) | lower(town), whitespace-collapsed, kept in sync
 * by the model. A unique index on it is the portable form of a unique
 * lower(company)+town index (works on MySQL and SQLite alike).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prospects')) {
            Schema::create('prospects', function (Blueprint $table) {
                $table->id();
                $table->string('company', 200);
                $table->string('dedupe_key', 255)->unique();
                $table->string('segment', 120)->nullable()->index();
                $table->string('town', 200)->nullable();
                $table->string('website', 500)->nullable();
                $table->text('why_they_need_us')->nullable();
                $table->string('contact_role', 200)->nullable();
                $table->string('contact_name', 200)->nullable();
                $table->string('contact_email', 255)->nullable()->index();
                $table->string('contact_phone', 60)->nullable();
                $table->string('linkedin_url', 500)->nullable();
                $table->unsignedTinyInteger('fit_score')->nullable();
                $table->text('angle')->nullable();
                $table->string('source_url', 1000)->nullable();
                $table->string('email_subject', 300)->nullable();
                $table->text('email_body')->nullable();
                $table->string('status', 24)->default('new')->index();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('next_action_at')->nullable()->index();
                $table->dateTime('last_contacted_at')->nullable();
                $table->dateTime('replied_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('prospect_activities')) {
            Schema::create('prospect_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type', 32); // imported | emailed | status | note | edited
                $table->text('body')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_activities');
        Schema::dropIfExists('prospects');
    }
};
