<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The heart of the system.
 *
 * bookings — one journey leg. Outbound and return are two linked rows
 *   (self-referencing linked_booking_id) so the rotation rule "same customer
 *   books outbound and return → same driver, rotation moves once" can be honoured.
 *
 * booking_stops — ordered via points between pickup and destination.
 *
 * booking_status_histories — full audit trail: every status transition with the
 *   user who made it and the GPS position captured at that moment (Phase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();

            // Parties
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('corporate_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cost_code')->nullable()->comment('Mandatory for cost-code corporate accounts');
            $table->string('corporate_reference')->nullable();

            // Service
            $table->foreignId('vehicle_type_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('airport_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Drives rotation when the vehicle type affects rotation');

            // Journey
            $table->string('journey_type', 16)->default('one_way')->comment('one_way | return');
            $table->boolean('is_return_leg')->default(false);
            $table->foreignId('linked_booking_id')->nullable()
                ->comment('Pairs an outbound leg with its return leg');
            $table->dateTime('pickup_at');
            $table->text('pickup_address');
            $table->string('pickup_postcode', 16)->nullable();
            $table->text('destination_address');
            $table->string('destination_postcode', 16)->nullable();
            $table->string('flight_number', 32)->nullable();
            $table->unsignedSmallInteger('passengers')->default(1);
            $table->unsignedSmallInteger('luggage')->default(0);
            $table->text('special_requests')->nullable();

            // Allocation & status (despatch board, Phase 2)
            $table->string('status', 24)->default('pending')
                ->comment('pending | allocated | accepted | en_route | collected | complete | cancelled | no_show');
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->boolean('affected_rotation')->default(false)
                ->comment('True if this booking advanced the rotation pointer');

            // Commercials
            $table->decimal('quoted_price', 10, 2)->nullable();
            $table->decimal('final_price', 10, 2)->nullable();
            $table->string('payment_method', 16)->default('card')->comment('cash | card | account');
            $table->string('payment_status', 24)->default('pending')
                ->comment('pending | paid | balance_remaining | invoiced | refunded');

            // Provenance & extensibility
            $table->string('source', 24)->default('phone')
                ->comment('phone | web | portal | email | outlook | whatsapp');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('pickup_at');
            $table->index(['driver_id', 'status']);
            $table->index(['payment_method', 'payment_status']);
            $table->foreign('linked_booking_id')->references('id')->on('bookings')->nullOnDelete();
        });

        Schema::create('booking_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->text('address');
            $table->string('postcode', 16)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'sequence']);
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_stops');
        Schema::dropIfExists('bookings');
    }
};
