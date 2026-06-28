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
 *   ABDI next: MAN, LHR, HUY, BHX, STN, LGW, LPL, LTN, LBA
 *   MAJ  next: EMA, Free Roam
 *
 * Any airport not present here ("all other unbooked airports") defaults to
 * ABDI via the rotation engine.
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
            'MAN' => $abdi,
            'LHR' => $abdi,
            'HUY' => $abdi,
            'BHX' => $abdi,
            'STN' => $abdi,
            'LGW' => $abdi,
            'LPL' => $abdi,
            'LTN' => $abdi,
            'LBA' => $abdi,
            'EMA' => $maj,
            'FREE_ROAM' => $maj,
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
