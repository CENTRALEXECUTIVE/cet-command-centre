<?php

namespace Database\Seeders;

use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use App\Services\Pricing\QuoteService;
use Illuminate\Database\Seeder;

/**
 * Mirrors the widget's built-in fixed-price matrix (QuoteService::rules()) into the
 * EDITABLE database matrix (pricing_zones + fixed_prices) that the /pricing editor
 * manages and the widget now reads first. Idempotent — safe to re-run; it only
 * upserts, so an office edit is never overwritten with the built-in value unless the
 * built-in value is re-seeded onto the exact same row (same zone+destination+vehicle).
 *
 * NB: this writes the SAME numbers the widget already quotes, so nothing changes for
 * customers until the office edits a price. Estate is derived (Executive + uplift),
 * so it is deliberately NOT stored here.
 */
class WidgetFixedPriceSeeder extends Seeder
{
    public function run(FixedPriceService $service): void
    {
        $zoneDefs = QuoteService::zoneDefinitions();
        $destNames = QuoteService::destinationNames();
        $vehicleIds = VehicleType::pluck('id', 'slug');

        // Ensure a PricingZone for each zone the matrix uses, with its postcode coverage.
        $zones = [];
        foreach ($zoneDefs as $slug => $def) {
            $zones[$slug] = PricingZone::updateOrCreate(
                ['slug' => $slug],
                ['name' => $def['name'], 'postcode_prefixes' => $def['prefixes'], 'is_active' => true],
            );
        }

        foreach (QuoteService::rules() as $rule) {
            foreach ($rule['dests'] as $destKey) {
                $destination = $destNames[$destKey] ?? ucwords(str_replace('-', ' ', $destKey));
                foreach ($rule['zones'] as $zoneSlug) {
                    $zone = $zones[$zoneSlug] ?? null;
                    if (! $zone) {
                        continue;
                    }
                    foreach ($rule['prices'] as $vehicleSlug => $price) {
                        if (! isset($vehicleIds[$vehicleSlug])) {
                            continue;
                        }
                        // Stored under the pretty destination name; its slug (Str::slug)
                        // is what QuoteService::dbPrice looks the row up by.
                        $service->upsert(
                            $zone,
                            $destination,
                            VehicleType::find($vehicleIds[$vehicleSlug]),
                            (float) $price,
                        );
                    }
                }
            }
        }
    }
}
