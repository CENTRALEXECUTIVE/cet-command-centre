<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push subscriptions — one row per device a driver has enabled
 * notifications on. Keyed by the push endpoint (unique). Deleted when the
 * device unsubscribes or the push service reports it gone (410).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique()->comment('sha256 of endpoint, for dedupe');
            $table->string('public_key');   // p256dh
            $table->string('auth_token');    // auth
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
