<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Scrub bad child-seat counts stored on bookings — e.g. child_seats
 * accidentally set to the passenger count ("7 child seats" on a 7-passenger
 * job whose calendar clearly says 1). For each affected booking:
 *   • if the calendar has a seats line, the stored counts are reconciled to it
 *     (the calendar is the source of truth);
 *   • otherwise any count above the passenger total is capped.
 *
 * Run with --dry-run first to see what would change.
 */
class FixChildSeats extends Command
{
    protected $signature = 'cet:fix-child-seats {--dry-run : Show what would change without saving}';

    protected $description = 'Remove impossible/wrong child-seat counts stored on bookings';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $seen = 0;

        Booking::query()->with('calendarEvent')->chunkById(200, function ($bookings) use (&$fixed, &$seen, $dry) {
            foreach ($bookings as $b) {
                $meta = $b->meta ?? [];
                $cs = (int) ($meta['child_seats'] ?? 0);
                $bs = (int) ($meta['booster_seats'] ?? 0);
                $is = (int) ($meta['infant_seats'] ?? 0);
                if ($cs === 0 && $bs === 0 && $is === 0) {
                    continue; // nothing stored to check
                }
                $seen++;

                $pax = max(1, (int) $b->passengers);
                $cal = $b->calendarChildSeats(); // authoritative text, or null

                $impossible = $cs > $pax || $bs > $pax || $is > $pax;
                $passengerLeak = ($cs >= $pax || $bs >= $pax || $is >= $pax) && $pax >= 3;
                $calendarDisagrees = $cal !== null;

                if (! $impossible && ! $passengerLeak && ! $calendarDisagrees) {
                    continue; // looks fine, leave it
                }

                [$newCs, $newBs, $newIs] = $this->corrected($cal, $cs, $bs, $is, $pax);

                if ($newCs === $cs && $newBs === $bs && $newIs === $is) {
                    continue; // no change after reconciliation
                }

                $this->line(sprintf(
                    '%s  child=%d→%d booster=%d→%d infant=%d→%d  (pax %d, calendar: %s)',
                    $b->reference, $cs, $newCs, $bs, $newBs, $is, $newIs, $pax, $cal ?? '—'
                ));

                $meta['child_seats'] = $newCs;
                $meta['booster_seats'] = $newBs;
                $meta['infant_seats'] = $newIs;
                $meta['child_seat'] = ($newCs + $newBs + $newIs) > 0;
                $fixed++;

                if (! $dry) {
                    $b->forceFill(['meta' => $meta])->save();
                }
            }
        });

        $this->info(($dry ? '[dry run] ' : '').$fixed.' booking(s) '.($dry ? 'would be' : '').' fixed out of '.$seen.' with stored seat counts.');

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int,2:int} [child, booster, infant] corrected counts */
    private function corrected(?string $cal, int $cs, int $bs, int $is, int $pax): array
    {
        if ($cal !== null) {
            // The calendar is authoritative. Pull a number for each seat type it
            // names; anything not named goes to 0. "None" → all zero.
            if (preg_match('/^(none|n\/?a|no\b|nil|0)/i', trim($cal))) {
                return [0, 0, 0];
            }
            $num = fn (string $type) => preg_match('/(\d+)\s*'.$type.'/i', $cal, $m) ? min((int) $m[1], $pax) : 0;
            $child = $num('child');
            $booster = $num('booster');
            $infant = $num('infant');
            // A bare "Child Seat" with no explicit type-count still means one seat.
            if ($child + $booster + $infant === 0 && preg_match('/(\d+)/', $cal, $m)) {
                $child = min((int) $m[1], $pax);
            }

            return [$child, $booster, $infant];
        }

        // No calendar to check against — just cap impossible values.
        $cap = fn (int $n) => ($n >= $pax && $pax >= 3) ? 0 : min($n, $pax);

        return [$cap($cs), $cap($bs), $cap($is)];
    }
}
