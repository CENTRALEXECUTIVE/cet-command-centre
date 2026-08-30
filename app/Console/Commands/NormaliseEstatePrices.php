<?php

namespace App\Console\Commands;

use App\Models\FixedPrice;
use App\Models\VehicleType;
use Illuminate\Console\Command;

/**
 * Sets every Estate fixed price to the matching Executive fare + the estate
 * uplift (config cet.estate_over_executive, default £10). Quoting already derives
 * this at runtime; this just makes the stored/admin figures agree, cleaning up the
 * ETO rows that were entered at +£5.
 */
class NormaliseEstatePrices extends Command
{
    protected $signature = 'cet:normalise-estate-prices {--dry : Show what would change without saving}';

    protected $description = 'Force every Estate fixed price to Executive + the estate uplift';

    public function handle(): int
    {
        $estate = VehicleType::where('slug', 'estate')->first();
        $executive = VehicleType::where('slug', 'executive')->first();
        if (! $estate || ! $executive) {
            $this->error('Need both an estate and an executive vehicle type.');

            return self::FAILURE;
        }

        $uplift = (float) config('cet.estate_over_executive', 10);
        $changed = 0;

        foreach (FixedPrice::where('vehicle_type_id', $executive->id)->where('is_active', true)->get() as $execRow) {
            $target = round((float) $execRow->price + $uplift, 2);

            $estateRow = FixedPrice::firstOrNew([
                'pricing_zone_id' => $execRow->pricing_zone_id,
                'destination_slug' => $execRow->destination_slug,
                'vehicle_type_id' => $estate->id,
            ]);

            if ($estateRow->exists && (float) $estateRow->price === $target) {
                continue;
            }
            $changed++;
            if ($this->option('dry')) {
                continue;
            }

            $estateRow->fill([
                'destination' => $execRow->destination,
                'price' => $target,
                'deposit' => $execRow->deposit,
                'is_active' => true,
            ])->save();
        }

        $this->info(($this->option('dry') ? 'Would update ' : 'Updated ')
            ."{$changed} estate price(s) to executive + £".number_format($uplift, 2).'.');

        return self::SUCCESS;
    }
}
