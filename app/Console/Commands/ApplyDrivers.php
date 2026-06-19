<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\User;
use App\Services\CalendarEventBuilder;
use Database\Seeders\RotationSeeder;
use Illuminate\Console\Command;

/**
 * Applies the operator's authoritative driver assignments (by ETO reference) to
 * the imported bookings, then resets the rotation pointer to the seeded order so
 * everything booked AFTER this list follows it. Drivers are set directly (no
 * rotation maths) — this list IS the source of truth. Reports any reference it
 * couldn't find so nothing is hidden.
 */
class ApplyDrivers extends Command
{
    protected $signature = 'cet:apply-drivers';

    protected $description = "Assign drivers from the operator's authoritative list and reset rotation to seeded";

    /** ABDI's jobs (a/b = both legs of a paired booking). */
    private const ABDI = [
        'XBQDOV', '5HRDJX', 'ASNZJMa', 'ASNZJMb', '04TNHY', 'I3SAHDa', 'I3SAHDb', '8DJTWP',
        'JVYQE0a', 'JVYQE0b', '2J9BWY', 'RJ6CHOa', 'RJ6CHOb', '1BGPNQa', '1BGPNQb',
        'FUJY46a', 'FUJY46b', 'EYJU8Ia', 'EYJU8Ib', 'D0H8JLa', 'D0H8JLb', 'M7LBXN', '6JHW5O',
        'TSIMKEa', 'TSIMKEb', 'CH9BQQ', 'GH1LUJ', '2YAQUG', 'CQNKSZa', 'CQNKSZb',
        'LSMNVNa', 'LSMNVNb', '4WDDGR', '983KBB', 'ZAU1XP',
        'JGLTHE', // MAJ's slot, ABDI covered → ABDI drives
    ];

    /** MAJ's jobs. */
    private const MAJ = [
        'B20NHR', '52ETDQ', 'XALWST', 'CVU153', 'SAOEV5', 'HEOWSU', 'PNFO67',
        'D0X5LA', 'CWJB1S', 'Y7YFXC', 'U31G0Xa', 'U31G0Xb', 'UGZA7D', 'FKAC5E', 'R8Y1FB',
        'L0TYQR', 'NU1TZ2a', 'NU1TZ2b', 'AGSICRa', 'AGSICRb', 'GVUPAV', '2904', 'ID3O2P',
    ];

    /** Cancelled — no driver. */
    private const CANCELLED = ['3XYLIE'];

    public function handle(CalendarEventBuilder $builder): int
    {
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        if (! $abdi || ! $maj) {
            $this->error('ABDI/MAJ driver accounts not found — run the base seeders first.');

            return self::FAILURE;
        }

        $assigned = 0;
        $notFound = [];

        foreach ([[$abdi, self::ABDI], [$maj, self::MAJ]] as [$driver, $refs]) {
            foreach ($refs as $ref) {
                $booking = Booking::where('source_system', 'eto')->where('external_reference', $ref)->first();
                if (! $booking) {
                    $notFound[] = $ref;

                    continue;
                }
                $booking->forceFill([
                    'driver_id' => $driver->id,
                    'is_return_leg' => str_ends_with($ref, 'b'),
                ])->save();
                $builder->buildFor($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));
                $assigned++;
            }
        }

        // Reset the rotation pointer to the seeded order for everything after.
        (new RotationSeeder)->run();

        $this->info("Assigned {$assigned} driver(s). Rotation reset to seeded order (ABDI: MAN/LHR/HUY/EMA/STN/LGW/LPL/Free Roam; MAJ: LBA/BHX).");
        if ($notFound) {
            $this->warn('Not found on the calendar (past, cancelled, or not imported): '.implode(', ', $notFound));
        }
        $this->line('Cancelled (left without a driver): '.implode(', ', self::CANCELLED));
        $this->line('Run cet:sync-calendar to push the updated driver tags.');

        return self::SUCCESS;
    }
}
