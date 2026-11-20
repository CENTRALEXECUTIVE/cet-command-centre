<?php

use App\Models\PricingZone;
use App\Services\Pricing\QuoteService;
use Illuminate\Database\Migrations\Migration;

/**
 * Directors, 20 Nov 2026: S72 (Cudworth, Grimethorpe) and S75 (Darton, Mapplewell,
 * Dodworth) join Deep Barnsley with S71. Barnsley keeps S70, S73, S74. Zone postcodes
 * only — the Deep Barnsley fares are already in place. Skipped under testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $defs = QuoteService::zoneDefinitions();
        foreach (['barnsley', 'barnsley-deep'] as $slug) {
            PricingZone::updateOrCreate(
                ['slug' => $slug],
                ['name' => $defs[$slug]['name'], 'postcode_prefixes' => $defs[$slug]['prefixes'], 'is_active' => true],
            );
        }
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
