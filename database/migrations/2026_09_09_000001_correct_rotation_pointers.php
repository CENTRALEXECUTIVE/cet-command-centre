<?php

use App\Models\Airport;
use App\Models\RotationState;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off correction: bring the live Executive rotation pointers exactly in line
 * with the real-world order the directors are running right now:
 *
 *   ABDI next: LHR, MAN, EMA, LTN, HUY, LPL, Free Roam
 *   MAJ  next: LBA, BHX, LGW, STN
 *
 * The pointers had drifted from reality (LBA/BHX/LGW/STN were showing ABDI when
 * MAJ is actually up), which made the rotation page wrong and confusing. This
 * sets them once; normal allocation advances them from here. Idempotent
 * (updateOrCreate) and a no-op in tests, which seed the rotation themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $executive = VehicleType::where('slug', 'executive')->first();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();

        if (! $executive || ! $abdi || ! $maj) {
            return; // environment not seeded yet — nothing to correct
        }

        $nextByAirport = [
            'LHR' => $abdi, 'MAN' => $abdi, 'EMA' => $abdi, 'LTN' => $abdi,
            'HUY' => $abdi, 'LPL' => $abdi, 'FREE_ROAM' => $abdi,
            'LBA' => $maj, 'BHX' => $maj, 'LGW' => $maj, 'STN' => $maj,
        ];

        foreach ($nextByAirport as $code => $driver) {
            $airport = Airport::where('code', $code)->first();
            if (! $airport) {
                continue;
            }

            RotationState::updateOrCreate(
                ['airport_id' => $airport->id, 'vehicle_type_id' => $executive->id],
                ['next_driver_id' => $driver->id, 'last_advanced_at' => now()],
            );
        }
    }

    public function down(): void
    {
        // No sensible rollback for a live-state correction.
    }
};
