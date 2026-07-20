<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Removes duplicate imported bookings — the same trip (same pickup minute + same
 * customer) that landed twice: an ETO file imported more than once, or a
 * calendar event auto-imported before the dedup match caught it. Keeps the
 * RICHEST copy — one carrying money data (tips/payroll) first, then a curated
 * import, then the most detail — and removes the rest.
 *
 *   php artisan cet:dedupe-bookings --dry-run   # preview, deletes nothing
 *   php artisan cet:dedupe-bookings             # actually remove them
 *
 * Only ever touches imported bookings (source_system eto/calendar); bookings
 * created by hand in the office are never de-duplicated automatically. Run the
 * calendar purge + sync afterwards to rebuild one clean event per booking.
 */
class DedupeBookings extends Command
{
    protected $signature = 'cet:dedupe-bookings {--dry-run : List duplicates without deleting anything}';

    protected $description = 'Remove duplicate imported bookings (same trip landed twice)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $bookings = Booking::whereIn('source_system', ['eto', 'calendar'])
            ->whereNotNull('pickup_at')
            ->with(['customer:id,phone'])
            ->withCount('tipEntries')
            ->get();

        // Same trip = same pickup minute AND same customer (phone, else id).
        $groups = $bookings->groupBy(
            fn ($b) => $b->pickup_at->format('YmdHi').'|'.($b->customer->phone ?? 'c'.$b->customer_id)
        );

        $removed = 0;
        $details = [];
        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            // Rank so the copy we KEEP is the safest: money data first (never
            // delete the one holding tips/payroll), then a curated import, then
            // the most detail, then the oldest.
            $sorted = $group->sort(function ($a, $b) {
                $am = ($a->tip_entries_count > 0 || ! empty($a->meta['payroll'])) ? 0 : 1;
                $bm = ($b->tip_entries_count > 0 || ! empty($b->meta['payroll'])) ? 0 : 1;
                if ($am !== $bm) {
                    return $am <=> $bm;
                }
                $ai = $a->source === 'import' ? 0 : 1;
                $bi = $b->source === 'import' ? 0 : 1;
                if ($ai !== $bi) {
                    return $ai <=> $bi;
                }
                $ak = count((array) ($a->meta ?? []));
                $bk = count((array) ($b->meta ?? []));
                if ($ak !== $bk) {
                    return $bk <=> $ak;
                }

                return $a->id <=> $b->id;
            })->values();

            $keep = $sorted->first();
            foreach ($sorted->slice(1) as $dupe) {
                $ref = $dupe->external_reference ?: $dupe->reference;
                $keepRef = $keep->external_reference ?: $keep->reference;
                $details[] = ($dry ? 'WOULD REMOVE ' : 'removed ')."{$ref} ({$dupe->pickup_at->format('D d M H:i')} {$dupe->displayName()}) — kept {$keepRef}";
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
