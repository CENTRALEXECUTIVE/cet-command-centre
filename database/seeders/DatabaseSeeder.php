<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the CET foundation data.
     * Order matters: vehicle types and directors must exist before rotation.
     */
    public function run(): void
    {
        $this->call([
            VehicleTypeSeeder::class,
            DirectorSeeder::class,
            AirportSeeder::class,
            RotationSeeder::class,
            CorporateSeeder::class,
            PricingSeeder::class,
            PricingZoneSeeder::class,
            FixedPriceSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
