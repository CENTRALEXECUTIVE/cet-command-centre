<?php

use App\Models\VehicleType;
use Illuminate\Database\Migrations\Migration;

/**
 * Bring the live vehicle passenger + luggage capacities in line with ETO's
 * "Types of Vehicles" (the operator's source of truth):
 *
 *   Executive        4 pax / 2 luggage   (luggage 3 → 2)
 *   Minibus 8 Seater 7 pax / 5 luggage   (pax 8 → 7, luggage 8 → 5)
 *   Minibus 8 XL     8 pax / 8 luggage   (luggage 12 → 8)
 *
 * V Class (7/7), Rolls (3/2) and Estate already match / have no ETO figure.
 * Idempotent; a no-op in tests, which seed vehicle types themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $capacities = [
            'executive' => ['passenger_capacity' => 4, 'luggage_capacity' => 2],
            'minibus-8' => ['passenger_capacity' => 7, 'luggage_capacity' => 5],
            'minibus-8-xl' => ['passenger_capacity' => 8, 'luggage_capacity' => 8],
        ];

        foreach ($capacities as $slug => $values) {
            VehicleType::where('slug', $slug)->update($values);
        }
    }

    public function down(): void
    {
        // No sensible rollback for a capacity correction.
    }
};
