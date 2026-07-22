<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver tips get their own table — an append-only ledger. Tips used to live in
 * the booking's meta JSON, which several calendar/import sync paths rewrite, so a
 * tip could be silently wiped. A dedicated table is money-grade: nothing but the
 * tip flow ever writes it, and a UNIQUE square_payment_id makes card tips
 * idempotent at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_tips')) {
            return;
        }

        Schema::create('booking_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 8, 2);
            $table->string('method', 10);            // cash | card
            $table->string('source', 20)->default('manual'); // manual | square
            $table->string('square_payment_id')->nullable()->unique();
            $table->string('note')->nullable();
            $table->string('logged_by')->nullable();
            $table->timestamps();
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_tips');
    }
};
