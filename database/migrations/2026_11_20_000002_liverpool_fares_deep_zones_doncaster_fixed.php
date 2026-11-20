<?php

use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use App\Services\Pricing\QuoteService;
use Illuminate\Database\Migrations\Migration;

/**
 * Directors, 20 Nov 2026:
 *  - Liverpool John Lennon fares go up (Sheffield/Rotherham/Barnsley £170 Exec,
 *    S20 / Deep Rotherham / Deep Barnsley £175; bigger vehicles up by the same £).
 *  - New zones: Deep Rotherham (S62–S66, taken out of Rotherham) and Deep Barnsley (S71).
 *  - Doncaster (DN1–DN12) back on fixed fares (code change; the zone is just kept active).
 *
 * Writes only the zones above and the Liverpool Airport rows — every other office-
 * edited fare is left alone. Skipped under testing.
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
        foreach (['rotherham', 'rotherham-deep', 'barnsley', 'barnsley-deep', 'doncaster'] as $slug) {
            $zones[$slug] = PricingZone::updateOrCreate(
                ['slug' => $slug],
                ['name' => $defs[$slug]['name'], 'postcode_prefixes' => $defs[$slug]['prefixes'], 'is_active' => true],
            );
        }

        $service = app(FixedPriceService::class);
        $destination = QuoteService::destinationNames()['liverpool'];
        $vehicles = VehicleType::all()->keyBy('slug');
        $raise = ['sheffield', 'rotherham', 'rotherham-deep', 'barnsley', 'barnsley-deep', 's20', 'chesterfield'];

        foreach (QuoteService::rules() as $rule) {
            if (! in_array('liverpool', $rule['dests'], true)) {
                continue;
            }
            foreach ($rule['zones'] as $slug) {
                if (! in_array($slug, $raise, true)) {
                    continue;
                }
                $zone = $zones[$slug] ?? PricingZone::where('slug', $slug)->first();
                if (! $zone) {
                    continue;
                }
                foreach ($rule['prices'] as $vehicleSlug => $price) {
                    if (! ($vt = $vehicles[$vehicleSlug] ?? null)) {
                        continue;
                    }
                    // Keep any deposit the office set on an existing row; only the price moves.
                    $row = \App\Models\FixedPrice::where('pricing_zone_id', $zone->id)
                        ->where('destination_slug', \Illuminate\Support\Str::slug($destination))
                        ->where('vehicle_type_id', $vt->id)->first();
                    $row ? $row->forceFill(['price' => (float) $price, 'is_active' => true])->save()
                        : $service->upsert($zone, $destination, $vt, (float) $price);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
