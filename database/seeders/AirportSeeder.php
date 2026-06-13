<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

class AirportSeeder extends Seeder
{
    public function run(): void
    {
        $airports = [
            ['code' => 'FREE_ROAM', 'name' => 'Free Roam / General Pool', 'is_general_pool' => true],
            ['code' => 'HUY', 'name' => 'Humberside'],
            ['code' => 'EMA', 'name' => 'East Midlands'],
            ['code' => 'STN', 'name' => 'London Stansted'],
            ['code' => 'LGW', 'name' => 'London Gatwick'],
            ['code' => 'LPL', 'name' => 'Liverpool John Lennon'],
            ['code' => 'LHR', 'name' => 'London Heathrow'],
            ['code' => 'MAN', 'name' => 'Manchester'],
            ['code' => 'LBA', 'name' => 'Leeds Bradford'],
            ['code' => 'BHX', 'name' => 'Birmingham'],
        ];

        foreach ($airports as $airport) {
            Airport::updateOrCreate(['code' => $airport['code']], $airport + ['is_active' => true]);
        }
    }
}
