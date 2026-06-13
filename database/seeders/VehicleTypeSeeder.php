<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    public function run(): void
    {
        // affects_rotation is true ONLY for the Executive saloon — the single
        // class that drives the Abdi/Maj rotation. Everything else is excluded.
        $types = [
            ['name' => 'Executive',        'slug' => 'executive',     'passenger_capacity' => 4,  'luggage_capacity' => 3, 'affects_rotation' => true,  'uses_third_party_driver' => false, 'sort_order' => 1],
            ['name' => 'Estate',           'slug' => 'estate',        'passenger_capacity' => 4,  'luggage_capacity' => 4, 'affects_rotation' => false, 'uses_third_party_driver' => true,  'sort_order' => 2],
            ['name' => 'V Class',          'slug' => 'v-class',       'passenger_capacity' => 7,  'luggage_capacity' => 7, 'affects_rotation' => false, 'uses_third_party_driver' => true,  'sort_order' => 3],
            ['name' => 'Minibus 8 Seater', 'slug' => 'minibus-8',     'passenger_capacity' => 8,  'luggage_capacity' => 8, 'affects_rotation' => false, 'uses_third_party_driver' => true,  'sort_order' => 4],
            ['name' => 'Minibus 8 XL',     'slug' => 'minibus-8-xl',  'passenger_capacity' => 8,  'luggage_capacity' => 12, 'affects_rotation' => false, 'uses_third_party_driver' => true,  'sort_order' => 5],
            ['name' => 'Rolls Royce Ghost', 'slug' => 'rolls-royce-ghost', 'passenger_capacity' => 3, 'luggage_capacity' => 2, 'affects_rotation' => false, 'uses_third_party_driver' => true,  'sort_order' => 6],
        ];

        foreach ($types as $type) {
            VehicleType::updateOrCreate(['slug' => $type['slug']], $type);
        }
    }
}
