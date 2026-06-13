<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments & invoicing (Phase 3/4).
 *
 * Card details are NEVER stored — only Tide payment links. Cash is flagged
 * separately and account work is invoiced automatically.
 *
 * payments — one per booking charge attempt/record.
 * invoices — monthly/periodic VAT invoices per corporate account.
 * invoice_items — booking lines on an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 32)->unique();
            $table->foreignId('corporate_account_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 16)->default('draft')->comment('draft | issued | paid | overdue | void');
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index(['corporate_account_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 16)->comment('cash | card | account');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status', 24)->default('pending')
                ->comment('pending | link_sent | paid | balance_remaining | failed | refunded');
            $table->string('tide_payment_link')->nullable()->comment('Card payments via Tide link only — no card data stored');
            $table->string('tide_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['method', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('cost_code')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
