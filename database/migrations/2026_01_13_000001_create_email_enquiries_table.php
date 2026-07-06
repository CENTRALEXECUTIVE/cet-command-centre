<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound customer enquiry emails pulled from Outlook: the AI-extracted journey,
 * the prepared quote and the drafted reply the operator reviews before sending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('graph_message_id')->nullable()->unique();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->json('extracted')->nullable();
            $table->decimal('quote_amount', 10, 2)->nullable();
            $table->string('quote_basis')->nullable();
            $table->longText('draft_reply')->nullable();
            $table->string('status', 16)->default('new')->index(); // new | quoted | replied | dismissed | booked
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_enquiries');
    }
};
