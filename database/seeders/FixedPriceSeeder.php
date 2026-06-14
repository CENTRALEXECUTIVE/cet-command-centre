<?php

namespace Database\Seeders;

use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a VERIFIED STARTER SUBSET of CET's fixed-price matrix, transcribed from
 * the ETO Fixed Prices screen. The full matrix should be loaded via
 * `php artisan cet:import-fixed-prices <export.csv>` to avoid transcription
 * error on 100+ routes.
 *
 * Price columns are [Estate, Executive, 8 Seater, 8 Seater XL, Executive 8 Seater].
 * A null Estate means that class is not offered/priced for the route.
 */
class FixedPriceSeeder extends Seeder
{
    public function run(FixedPriceService $service): void
    {
        // Origin zones (postcode prefixes added where known).
        $zones = [
            'Barnsley' => null,
            'Barnsley Deep' => null,
            'Nottingham 5 Mile Zone' => null,
            'Sheffield S20' => ['S20'],
            'Doncaster' => null,
        ];
        $zoneModels = [];
        foreach ($zones as $name => $prefixes) {
            $zoneModels[$name] = PricingZone::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'postcode_prefixes' => $prefixes],
            );
        }

        // [Estate, Executive, 8 Seater, 8 Seater XL, Executive 8 Seater]
        $matrix = [
            'Barnsley' => [
                'East Midlands Airport' => [105, 100, 150, 165, 175],
            ],
            'Barnsley Deep' => [
                'Manchester Airport' => [115, 110, 150, 165, 175],
                'Stansted Airport' => [305, 300, 360, 450, 450],
                'Gatwick Airport' => [375, 370, 460, 475, 550],
                'Central London' => [335, 330, 400, 415, 450],
                'Exeter Airport' => [455, 450, 560, 575, 650],
                'Luton Airport' => [275, 270, 320, 335, 410],
                'Heathrow Airport' => [325, 320, 380, 395, 450],
            ],
            'Nottingham 5 Mile Zone' => [
                'Heathrow Airport' => [315, 310, 390, 405, 480],
            ],
            'Sheffield S20' => [
                'Liverpool Airport' => [160, 155, 195, 210, 220],
            ],
            'Doncaster' => [
                'Humberside Airport' => [null, 85, 130, 150, 160],
            ],
        ];

        $slugByIndex = ['estate', 'executive', 'minibus-8', 'minibus-8-xl', 'v-class'];
        $vehicleTypes = VehicleType::pluck('id', 'slug');

        foreach ($matrix as $zoneName => $destinations) {
            foreach ($destinations as $destination => $prices) {
                foreach ($prices as $i => $price) {
                    if ($price === null || ! isset($vehicleTypes[$slugByIndex[$i]])) {
                        continue;
                    }
                    $service->upsert(
                        $zoneModels[$zoneName],
                        $destination,
                        VehicleType::find($vehicleTypes[$slugByIndex[$i]]),
                        (float) $price,
                    );
                }
            }
        }
    }
}
