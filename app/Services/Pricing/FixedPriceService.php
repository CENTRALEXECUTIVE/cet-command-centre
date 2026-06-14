<?php

namespace App\Services\Pricing;

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Models\VehicleType;
use Illuminate\Support\Str;

/**
 * Looks up CET's fixed-price matrix (origin zone → destination → vehicle type).
 * A fixed price, when one exists, is authoritative over distance/time pricing.
 */
class FixedPriceService
{
    /**
     * Maps the ETO fixed-price column labels to CET vehicle-type slugs.
     * "Executive 8 Seater" is the V-Class; the plain "8 Seater" classes are the
     * minibuses; "Luxury" is the Rolls-Royce Ghost.
     */
    public const COLUMN_TO_SLUG = [
        'estate' => 'estate',
        'executive' => 'executive',
        '8 seater' => 'minibus-8',
        '8 seater xl' => 'minibus-8-xl',
        'executive 8 seater' => 'v-class',
        'luxury' => 'rolls-royce-ghost',
    ];

    public function destinationSlug(string $destination): string
    {
        return Str::slug($destination);
    }

    public function slugForColumn(string $label): ?string
    {
        return self::COLUMN_TO_SLUG[strtolower(trim($label))] ?? null;
    }

    /**
     * Find the fixed price for a zone, destination and vehicle type, or null.
     */
    public function lookup(int|PricingZone $zone, string $destination, VehicleType $vehicleType): ?FixedPrice
    {
        $zoneId = $zone instanceof PricingZone ? $zone->id : $zone;

        return FixedPrice::query()
            ->where('is_active', true)
            ->where('pricing_zone_id', $zoneId)
            ->where('destination_slug', $this->destinationSlug($destination))
            ->where('vehicle_type_id', $vehicleType->id)
            ->first();
    }

    /** Resolve the zone for a postcode by its outward code (e.g. "S20"). */
    public function zoneForPostcode(?string $postcode): ?PricingZone
    {
        if (blank($postcode)) {
            return null;
        }

        $outward = strtoupper(trim(Str::before(trim($postcode), ' ') ?: $postcode));

        return PricingZone::where('is_active', true)->get()
            ->first(fn (PricingZone $zone) => collect($zone->postcode_prefixes ?? [])
                ->contains(fn ($prefix) => strtoupper($prefix) === $outward));
    }

    /** Upsert a single fixed price (used by the seeder and CSV importer). */
    public function upsert(PricingZone $zone, string $destination, VehicleType $vehicleType, float $price, float $deposit = 0): FixedPrice
    {
        return FixedPrice::updateOrCreate(
            [
                'pricing_zone_id' => $zone->id,
                'destination_slug' => $this->destinationSlug($destination),
                'vehicle_type_id' => $vehicleType->id,
            ],
            [
                'destination' => $destination,
                'price' => $price,
                'deposit' => $deposit,
                'is_active' => true,
            ],
        );
    }
}
