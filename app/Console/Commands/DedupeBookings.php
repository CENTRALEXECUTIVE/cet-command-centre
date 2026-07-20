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
                $when = $dupe->pickup_at?->format('D d M H:i') ?? 'no time';
                $details[] = ($dry ? 'WOULD MERGE ' : 'merged ')."{$ref} ({$when} {$dupe->displayName()}) → kept copy #{$keep->id}";
                if (! $dry) {
                    $this->mergeInto($keep, $dupe);   // survivor absorbs anything it's missing
                    $dupe->forceDelete();
                    $removed++;
                }
            }
        }

        foreach (array_slice($details, 0, 60) as $d) {
            $this->line('  '.$d);
        }

        if ($dry) {
            $this->info(count($details).' duplicate(s) found. Re-run without --dry-run to merge & remove them.');

            return self::SUCCESS;
        }

        $this->info("Merged & removed {$removed} duplicate booking(s).");
        if ($removed > 0) {
            $this->line('Now run: php artisan cet:purge-calendar && php artisan cet:sync-calendar');
        }

        return self::SUCCESS;
    }

    /**
     * Fold everything useful from a duplicate into the copy we're keeping, so
     * removing the duplicate loses nothing — a "replace", not a bare delete.
     */
    private function mergeInto(Booking $keep, Booking $dupe): void
    {
        // Money moves with the journey — reassign any tips to the survivor.
        \App\Models\BookingTip::where('booking_id', $dupe->id)->update(['booking_id' => $keep->id]);

        // Take the calendar link if the survivor hasn't got one.
        if (! $keep->calendarEvent && $dupe->calendarEvent) {
            $dupe->calendarEvent->forceFill(['booking_id' => $keep->id])->save();
        }

        // Fill any blank fields on the survivor from the duplicate.
        $fill = [];
        foreach ([
            'driver_id', 'vehicle_id', 'airport_id', 'quoted_price', 'final_price',
            'flight_number', 'destination_address', 'pickup_address', 'special_requests',
        ] as $col) {
            if (blank($keep->$col) && filled($dupe->$col)) {
                $fill[$col] = $dupe->$col;
            }
        }
        // Merge meta so nothing (payroll, notes, tokens, audit) is lost —
        // the survivor's own values win any conflict.
        $fill['meta'] = array_merge($dupe->meta ?? [], $keep->meta ?? []);
        $keep->forceFill($fill)->save();
    }
}
