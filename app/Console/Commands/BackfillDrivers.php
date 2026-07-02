<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfills the driver on bookings from the driver tag already shown on their
 * calendar event title — e.g. "*Rebecca Fielding MAN (ABDI)*" → assign Abdi.
 * Imported bookings kept the driver only in the calendar title, never on the
 * booking record, so the despatch board showed them as unallocated. This writes
 * the driver onto the booking so the board matches the calendar.
 *
 * Only fills bookings that have NO driver yet (never overwrites a real
 * allocation) and only for tags that map to a real person (ABDI / MAJ / a named
 * third-party driver). Vehicle-only tags (V CLASS, MINIBUS, EXECUTIVE, …) are
 * left unallocated. Rotation state is NOT changed — this only reflects reality.
 *
 * Usage:  php artisan cet:backfill-drivers          (report + apply)
 *         php artisan cet:backfill-drivers --dry-run (report only)
 */
class BackfillDrivers extends Command
{
    protected $signature = 'cet:backfill-drivers {--dry-run : show what would change without saving}';

    protected $description = 'Set the driver on bookings from their calendar title tag (ABDI/MAJ/named driver)';

    public function handle(): int
    {
        $map = $this->driverMap();
        if ($map === []) {
            $this->error('No driver users found to map tags to.');

            return self::FAILURE;
        }

        $bookings = Booking::whereNull('driver_id')
            ->whereHas('calendarEvent')
            ->with('calendarEvent')
            ->get();

        $assigned = 0;
        $skipped = [];
        foreach ($bookings as $booking) {
            $tag = $this->tagFrom($booking->calendarEvent->title ?? '');
            $driver = $tag !== null ? ($map[$tag] ?? null) : null;

            if (! $driver) {
                if ($tag !== null) {
                    $skipped[$tag] = ($skipped[$tag] ?? 0) + 1; // vehicle/unknown tag
                }

                continue;
            }

            if (! $this->option('dry-run')) {
                $booking->forceFill(['driver_id' => $driver->id])->save();
            }
            $assigned++;
        }

        $verb = $this->option('dry-run') ? 'Would assign' : 'Assigned';
        $this->info("{$verb} a driver to {$assigned} booking(s) from their calendar tag.");
        if ($skipped !== []) {
            $this->line('Left unallocated (not a person tag): '
                .collect($skipped)->map(fn ($n, $t) => "{$t}×{$n}")->implode(', '));
        }

        return self::SUCCESS;
    }

    /** The bracketed tag at the end of the title, e.g. "…(ABDI)*" → "ABDI". */
    private function tagFrom(string $title): ?string
    {
        return preg_match('/\(([^)]+)\)\*?\s*$/', $title, $m)
            ? Str::upper(trim($m[1]))
            : null;
    }

    /**
     * Map every callsign/first-name to its driver User, e.g. ABDI → Abdirazak,
     * MAJ → Majid, KASH → (third party) — whatever driver profiles exist.
     *
     * @return array<string, User>
     */
    private function driverMap(): array
    {
        $map = [];
        $drivers = User::where('is_active', true)
            ->whereHas('driverProfile')
            ->get();

        foreach ($drivers as $driver) {
            $callsign = Str::upper(Str::before((string) $driver->email, '@'));
            if ($callsign !== '') {
                $map[$callsign] = $driver;
            }
            $first = Str::upper(Str::before((string) $driver->name, ' '));
            if ($first !== '' && ! isset($map[$first])) {
                $map[$first] = $driver;
            }
        }

        return $map;
    }
}
