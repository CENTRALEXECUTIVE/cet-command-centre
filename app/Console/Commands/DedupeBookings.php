<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Removes duplicate imported bookings — two records that share the same ETO
 * reference number are the SAME booking (an ETO file imported twice, or a
 * calendar event auto-imported before the dedup match caught it). The reference
 * is the identity, NOT the pickup time — a customer can reschedule and we update
 * the time, so time is not a reliable key. Keeps the RICHEST copy — one carrying
 * money data (tips/payroll) first, then a curated import, then the most detail —
 * and removes the rest.
 *
 *   php artisan cet:dedupe-bookings --dry-run   # preview, deletes nothing
 *   php artisan cet:dedupe-bookings             # actually remove them
 *
 * Only groups bookings that actually carry a reference; anything without one is
 * left untouched. Run the calendar purge + sync afterwards to rebuild one clean
 * event per booking.
 */
class DedupeBookings extends Command
{
    protected $signature = 'cet:dedupe-bookings {--dry-run : List duplicates without deleting anything}';

    protected $description = 'Remove duplicate bookings that share an ETO reference number';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $bookings = Booking::whereNotNull('external_reference')
            ->where('external_reference', '!=', '')
            ->with(['customer:id,phone', 'calendarEvent:id,booking_id'])
            ->withCount('tipEntries')
            ->get();

        // Same booking = same ETO reference number (case/space-insensitive).
        $groups = $bookings->groupBy(fn ($b) => strtoupper(trim((string) $b->external_reference)));

        $removed = 0;
        $details = [];
        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            // Rank so we KEEP the copy you've actually worked. Lower wins:
            //   1. carries money data (tips/payroll)   — never delete money
            //   2. not cancelled                        — keep the live one
            //   3. has a driver assigned                — it's been worked
            //   4. linked to the Google calendar event  — the "real" one
            //   5. a curated import over an auto-import
            //   6. the most detail, then the oldest
            $rank = fn (Booking $b) => [
                ($b->tip_entries_count > 0 || ! empty($b->meta['payroll'])) ? 0 : 1,
                $b->status?->value === 'cancelled' ? 1 : 0,
                $b->driver_id ? 0 : 1,
                $b->calendarEvent ? 0 : 1,
                $b->source === 'import' ? 0 : 1,
                -count((array) ($b->meta ?? [])),
                $b->id,
            ];
            $sorted = $group->sortBy($rank)->values();

            $keep = $sorted->first();
            foreach ($sorted->slice(1) as $dupe) {
                $ref = $dupe->external_reference ?: $dupe->reference;
                $keepRef = $keep->external_reference ?: $keep->reference;
                $when = $dupe->pickup_at?->format('D d M H:i') ?? 'no time';
                $details[] = ($dry ? 'WOULD REMOVE ' : 'removed ')."{$ref} ({$when} {$dupe->displayName()}) — kept {$keepRef}";
                if (! $dry) {
                    $dupe->forceDelete();
                    $removed++;
                }
            }
        }

        foreach (array_slice($details, 0, 60) as $d) {
            $this->line('  '.$d);
        }

        if ($dry) {
            $this->info(count($details).' duplicate(s) found. Re-run without --dry-run to remove them.');

            return self::SUCCESS;
        }

        $this->info("Removed {$removed} duplicate booking(s).");
        if ($removed > 0) {
            $this->line('Now run: php artisan cet:purge-calendar && php artisan cet:sync-calendar');
        }

        return self::SUCCESS;
    }
}
