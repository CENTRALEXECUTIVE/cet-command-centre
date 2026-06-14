<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\RotationState;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

/**
 * Seeds the live rotation pointer EXACTLY as it stands today, for the Executive
 * saloon (the only rotation-affecting vehicle type):
 *
 *   ABDI next: MAN, LHR, HUY, EMA, STN, LGW, LPL, Free Roam
 *   MAJ  next: LBA, BHX
 *
 * Any airport not present here defaults to ABDI via the rotation engine.
 */
class RotationSeeder extends Seeder
{
    public function run(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();

        if (! $executive || ! $abdi || ! $maj) {
            return;
        }

        $nextDriverByAirport = [
            'FREE_ROAM' => $abdi,
            'MAN' => $abdi,
            'LHR' => $abdi,
            'HUY' => $abdi,
            'EMA' => $abdi,
            'STN' => $abdi,
            'LGW' => $abdi,
            'LPL' => $abdi,
            'LBA' => $maj,
            'BHX' => $maj,
        ];

        foreach ($nextDriverByAirport as $code => $driver) {
            $airport = Airport::where('code', $code)->first();
            if (! $airport) {
                continue;
            }

            RotationState::updateOrCreate(
                ['airport_id' => $airport->id, 'vehicle_type_id' => $executive->id],
                ['next_driver_id' => $driver->id]
            );
        }
    }
}
