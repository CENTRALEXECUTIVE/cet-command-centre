<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer CRM.
 *
 * customers — every passenger the system has ever served, with preferred
 *   pickup and preferred vehicle so the system "remembers every customer".
 *   GDPR marketing consent captured here; erasure handled via the governance
 *   tables.
 * customer_addresses — saved pickup/destination addresses for fast re-booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('corporate_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('preferred_pickup_address')->nullable();
            $table->foreignId('preferred_vehicle_type_id')->nullable()->constrained('vehicle_types')->nullOnDelete();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->boolean('is_vip')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('phone');
            $table->index('email');
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable()->comment('Home, Office, etc.');
            $table->text('address');
            $table->string('postcode', 16)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
