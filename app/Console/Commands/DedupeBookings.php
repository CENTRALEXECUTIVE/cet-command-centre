<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Removes duplicate ETO bookings — the same trip (same pickup time + same
 * customer phone) imported more than once under different references (e.g. the
 * curated file and the live email feed). Keeps the richest copy (curated import
 * preferred, then the one with the most detail) and deletes the rest. Run the
 * calendar purge + sync afterwards to rebuild one clean event per booking.
 */
class DedupeBookings extends Command
{
    protected $signature = 'cet:dedupe-bookings';

    protected $description = 'Remove duplicate ETO bookings (same trip under different references)';

    public function handle(): int
    {
        $bookings = Booking::where('source_system', 'eto')
            ->whereNotNull('pickup_at')
            ->with('customer:id,phone')
            ->get();

        $groups = $bookings->groupBy(
            fn ($b) => $b->pickup_at->format('YmdHi').'|'.($b->customer->phone ?? 'c'.$b->customer_id)
        );

        $removed = 0;
        $details = [];
        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            // Keep the richest: curated import first, then most meta keys, then oldest.
            $sorted = $group->sort(function ($a, $b) {
                $ai = $a->source === 'import' ? 0 : 1;
                $bi = $b->source === 'import' ? 0 : 1;
                if ($ai !== $bi) {
                    return $ai <=> $bi;
                }
                $am = count((array) ($a->meta ?? []));
                $bm = count((array) ($b->meta ?? []));
                if ($am !== $bm) {
                    return $bm <=> $am;
                }

                return $a->id <=> $b->id;
            })->values();

            $keep = $sorted->first();
            foreach ($sorted->slice(1) as $dupe) {
                $details[] = "{$dupe->external_reference} (kept {$keep->external_reference})";
                $dupe->forceDelete();
                $removed++;
            }
        }

        $this->info("Removed {$removed} duplicate booking(s).");
        foreach (array_slice($details, 0, 40) as $d) {
            $this->line('  '.$d);
        }
        $this->line('Now run: php artisan cet:purge-calendar && php artisan cet:sync-calendar');

        return self::SUCCESS;
    }
}
