<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Silent log of every website booking attempt and every "On request" price (26 Nov 2026). */
    public function up(): void
    {
        if (Schema::hasTable('booking_attempts')) {
            return;
        }
        Schema::create('booking_attempts', function (Blueprint $t) {
            $t->id();
            $t->string('form', 40);              // widget | book | prices
            $t->string('outcome', 30);           // booked | quote_request | refused | error | price_on_request
            $t->foreignId('booking_id')->nullable()->index();
            $t->string('reference', 40)->nullable();
            $t->string('customer_name', 160)->nullable();
            $t->string('contact', 190)->nullable();
            $t->string('pickup', 500)->nullable();
            $t->string('destination', 500)->nullable();
            $t->string('pickup_at', 40)->nullable();
            $t->text('details')->nullable();     // JSON: errors / vehicles / message
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_attempts');
    }
};
