<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded driver/vehicle compliance documents (the actual files) with their
 * expiry and a verification state. The days-left cards on the driver app read
 * from here; approval feeds the expiry into the compliance tracking.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->comment('driving_licence | badge | dbs | plate | mot | insurance');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 16)->default('pending')->comment('pending | approved | rejected');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']); // one current document per type per driver
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
