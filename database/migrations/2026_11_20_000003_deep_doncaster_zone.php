<?php

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Services\Pricing\QuoteService;
use Illuminate\Database\Migrations\Migration;

/**
 * Directors, 20 Nov 2026: DN7 (Hatfield) was getting the Doncaster town fare — too far.
 * Deep Doncaster = DN6, DN7, DN8, DN9, DN12 (as ETO had it), every Doncaster fare + £10.
 * Doncaster keeps DN1–DN5, DN10, DN11. Copies the CURRENT Doncaster rows (so any office
 * edit carries over) at + £10, keeping deposits. Skipped under testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $defs = QuoteService::zoneDefinitions();
        $zones = [];
        foreach (['doncaster', 'doncaster-deep'] as $slug) {
            $zones[$slug] = PricingZone::updateOrCreate(
                ['slug' => $slug],
                ['name' => $defs[$slug]['name'], 'postcode_prefixes' => $defs[$slug]['prefixes'], 'is_active' => true],
            );
        }

        foreach (FixedPrice::where('pricing_zone_id', $zones['doncaster']->id)->get() as $row) {
            FixedPrice::updateOrCreate(
                [
                    'pricing_zone_id' => $zones['doncaster-deep']->id,
                    'destination_slug' => $row->destination_slug,
                    'vehicle_type_id' => $row->vehicle_type_id,
                ],
                [
                    'destination' => $row->destination,
                    'price' => (float) $row->price + QuoteService::DEEP_DONCASTER_EXTRA,
                    'deposit' => $row->deposit ?? 0,
                    'is_active' => (bool) $row->is_active,
                ],
            );
        }
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
