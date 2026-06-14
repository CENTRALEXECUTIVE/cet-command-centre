<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-price matrix — how CET actually prices its core airport/port work.
 *
 * pricing_zones — origin zones (Barnsley, Barnsley Deep, Sheffield S20,
 *   Chesterfield, Doncaster, Worksop, Mansfield, Matlock, Rotherham,
 *   Nottingham 5 Mile Zone, …) optionally matched to postcodes.
 *
 * fixed_prices — a fixed fare for (zone → destination, per vehicle type), with
 *   an optional deposit. Authoritative over distance/time pricing when matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('postcode_prefixes')->nullable()->comment('Outward codes that map to this zone');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fixed_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_zone_id')->constrained()->cascadeOnDelete();
            $table->string('destination')->comment('e.g. Manchester Airport, Heathrow Airport, Central London');
            $table->string('destination_slug')->index();
            $table->foreignId('vehicle_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->decimal('deposit', 10, 2)->default(0);
            $table->boolean('both_ways')->default(true)->comment('Same fare applies in either direction');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pricing_zone_id', 'destination_slug', 'vehicle_type_id'], 'fixed_price_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_prices');
        Schema::dropIfExists('pricing_zones');
    }
};
