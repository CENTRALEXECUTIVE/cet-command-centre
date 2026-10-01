<?php

use App\Services\Pricing\FixedPriceService;
use Database\Seeders\WidgetFixedPriceSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Populate the editable fixed-price matrix (pricing_zones + fixed_prices) from the
 * widget's built-in matrix, so the /pricing editor is pre-filled with the real fares
 * and the office can change them (the widget now reads the DB first). Writes the same
 * numbers the widget already quotes, so no customer price changes until it's edited.
 *
 * Skipped under testing (tests seed the matrix explicitly when they need it). Safe to
 * run more than once — the seeder upserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        app(WidgetFixedPriceSeeder::class)->run(app(FixedPriceService::class));
    }

    public function down(): void
    {
        // Non-destructive: leave the editable matrix in place.
    }
};
