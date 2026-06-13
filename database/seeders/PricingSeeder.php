<?php

namespace Database\Seeders;

use App\Models\BankHoliday;
use App\Models\PricingRule;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

/**
 * Seeds starting fare rules per vehicle type and the UK bank holidays the AI
 * pricing engine surcharges. Figures are sensible starting points the office
 * can tune.
 */
class PricingSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            'executive' => ['base_fare' => 8, 'per_mile' => 2.20, 'per_minute' => 0.30, 'minimum_fare' => 15, 'airport_surcharge' => 5, 'night_multiplier' => 1.30],
            'estate' => ['base_fare' => 8, 'per_mile' => 2.30, 'per_minute' => 0.30, 'minimum_fare' => 16, 'airport_surcharge' => 5, 'night_multiplier' => 1.30],
            'v-class' => ['base_fare' => 15, 'per_mile' => 3.00, 'per_minute' => 0.40, 'minimum_fare' => 35, 'airport_surcharge' => 8, 'night_multiplier' => 1.30],
            'minibus-8' => ['base_fare' => 20, 'per_mile' => 3.20, 'per_minute' => 0.45, 'minimum_fare' => 45, 'airport_surcharge' => 10, 'night_multiplier' => 1.30],
            'minibus-8-xl' => ['base_fare' => 25, 'per_mile' => 3.50, 'per_minute' => 0.50, 'minimum_fare' => 55, 'airport_surcharge' => 12, 'night_multiplier' => 1.30],
            'rolls-royce-ghost' => ['base_fare' => 80, 'per_mile' => 5.00, 'per_minute' => 1.00, 'minimum_fare' => 250, 'airport_surcharge' => 20, 'night_multiplier' => 1.25],
        ];

        foreach ($rates as $slug => $rate) {
            $vt = VehicleType::where('slug', $slug)->first();
            if (! $vt) {
                continue;
            }
            PricingRule::updateOrCreate(
                ['vehicle_type_id' => $vt->id],
                $rate + ['night_starts_at' => '22:00:00', 'night_ends_at' => '06:00:00', 'peak_multiplier' => 1.0, 'is_active' => true],
            );
        }

        $holidays = [
            ['date' => '2026-01-01', 'name' => "New Year's Day", 'surcharge_percent' => 20],
            ['date' => '2026-04-03', 'name' => 'Good Friday', 'surcharge_percent' => 15],
            ['date' => '2026-04-06', 'name' => 'Easter Monday', 'surcharge_percent' => 15],
            ['date' => '2026-05-04', 'name' => 'Early May Bank Holiday', 'surcharge_percent' => 15],
            ['date' => '2026-05-25', 'name' => 'Spring Bank Holiday', 'surcharge_percent' => 15],
            ['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'surcharge_percent' => 15],
            ['date' => '2026-12-25', 'name' => 'Christmas Day', 'surcharge_percent' => 30],
            ['date' => '2026-12-26', 'name' => 'Boxing Day', 'surcharge_percent' => 25],
            ['date' => '2026-12-28', 'name' => 'Boxing Day (substitute)', 'surcharge_percent' => 25],
        ];

        foreach ($holidays as $holiday) {
            BankHoliday::updateOrCreate(['date' => $holiday['date']], $holiday);
        }
    }
}
