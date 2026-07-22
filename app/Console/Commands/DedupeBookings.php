<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * Removes duplicate bookings. Two things can create a duplicate:
 *
 *  1. The SAME ETO reference on two records — an ETO file imported twice, or a
 *     calendar event auto-imported before the dedupe match caught it. The
 *     reference is the identity (NOT the pickup time — a customer can reschedule
 *     and we update the time, so time is not a reliable key on its own).
 *
 *  2. The SAME JOURNEY with a missing (or only one) reference — the classic
 *     "two in the Command Centre, one on the calendar": the old paste-a-booking
 *     tool created a booking with no reference, then the same job was added to
 *     the calendar and imported with the ETO reference. These never share a
 *     reference, so the first pass can't see them. The second pass matches them
 *     by journey (same pickup minute AND same customer) — but NEVER merges two
 *     records that carry DIFFERENT references, because by rule those are two
 *     genuinely different bookings (e.g. a re-book that isn't on the calendar
 *     yet).
 *
 * Either way it keeps the RICHEST copy — one carrying money data (tips/payroll)
 * first, then a curated import, then the most detail — folds everything useful
 * from the others into it, and removes them. All dates are covered (there is no
 * "recent only" cut-off).
 *
 *   php artisan cet:dedupe-bookings --dry-run   # preview, deletes nothing
 *   php artisan cet:dedupe-bookings             # actually remove them
 */
class DedupeBookings extends Command
{
    protected $signature = 'cet:dedupe-bookings {--dry-run : List duplicates without deleting anything}';

    protected $description = 'Remove duplicate bookings (same ETO reference, or same journey with no reference match)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $bookings = Booking::with(['customer:id,phone,name', 'calendarEvent:id,booking_id'])
            ->withCount('tipEntries')
            ->get();

        $removedIds = [];
        $details = [];
        $removed = 0;

        // ---- Pass 1: same ETO reference (the booking's identity). ----
        $refGroups = $bookings
            ->filter(fn ($b) => filled(trim((string) $b->external_reference)))
            ->groupBy(fn ($b) => strtoupper(trim((string) $b->external_reference)));

        foreach ($refGroups as $group) {
            if ($group->count() < 2) {
                continue;
            }
            $sorted = $group->sortBy($this->rank())->values();
            $keep = $sorted->first();
            foreach ($sorted->slice(1) as $dupe) {
                $details[] = $this->describe($dupe, $keep, 'same reference', $dry);
                $removedIds[] = $dupe->id;
                if (! $dry) {
                    $this->mergeInto($keep, $dupe);
                    $dupe->forceDelete();
                    $removed++;
                }
            }
        }

        // ---- Pass 2: same journey, missing / one reference. ----
        $survivors = $bookings->reject(fn ($b) => in_array($b->id, $removedIds, true));
        $sigGroups = $survivors
            ->filter(fn ($b) => $this->signature($b) !== null)
            ->groupBy(fn ($b) => $this->signature($b));

        foreach ($sigGroups as $group) {
            if ($group->count() < 2) {
                continue;
            }
            // Two or more DIFFERENT references in the same slot = genuinely
            // different bookings (a re-book not on the calendar yet). Leave them.
            $distinctRefs = $group
                ->map(fn ($b) => strtoupper(trim((string) $b->external_reference)))
                ->filter(fn ($r) => $r !== '')
                ->unique();
            if ($distinctRefs->count() >= 2) {
                continue;
            }

            $sorted = $group->sortBy($this->rank())->values();
            $keep = $sorted->first();
            foreach ($sorted->slice(1) as $dupe) {
                $details[] = $this->describe($dupe, $keep, 'same journey, no ref match', $dry);
                if (! $dry) {
                    $this->mergeInto($keep, $dupe);
                    $dupe->forceDelete();
                    $removed++;
                }
            }
        }

        foreach (array_slice($details, 0, 80) as $d) {
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
     * Keep-rank (lower wins): keep the copy you've actually worked.
     *   1. carries money data (tips/payroll)   — never delete money
     *   2. not cancelled                        — keep the live one
     *   3. has a driver assigned                — it's been worked
     *   4. linked to the Google calendar event  — the "real" one
     *   5. a curated import over an auto-import
     *   6. the most detail, then the oldest
     */
    private function rank(): callable
    {
        return fn (Booking $b) => [
            ($b->tip_entries_count > 0 || ! empty($b->meta['payroll'])) ? 0 : 1,
            $b->status?->value === 'cancelled' ? 1 : 0,
            $b->driver_id ? 0 : 1,
            $b->calendarEvent ? 0 : 1,
            $b->source === 'import' ? 0 : 1,
            -count((array) ($b->meta ?? [])),
            $b->id,
        ];
    }

    /**
     * A journey signature for the second pass: the pickup minute + the customer
     * (phone if we have it, otherwise the name). Null when we can't identify the
     * journey confidently — those are never matched, so we never collapse two
     * different jobs that merely share a time.
     */
    private function signature(Booking $b): ?string
    {
        if (! $b->pickup_at) {
            return null;
        }

        $phone = Phone::wa($b->customer?->phone);
        if (filled($phone)) {
            $customer = 'p:'.$phone;
        } elseif (filled($b->customer?->name)) {
            $customer = 'n:'.strtolower(preg_replace('/\s+/', ' ', trim($b->customer->name)));
        } else {
            return null;
        }

        return $b->pickup_at->format('YmdHi').'|'.$customer;
    }

    private function describe(Booking $dupe, Booking $keep, string $why, bool $dry): string
    {
        $ref = $dupe->external_reference ?: $dupe->reference ?: 'no ref';
        $when = $dupe->pickup_at?->format('D d M H:i') ?? 'no time';

        return ($dry ? 'WOULD MERGE ' : 'merged ')."{$ref} ({$when} {$dupe->displayName()}) [{$why}] → kept copy #{$keep->id}";
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

        // Take the reference if the survivor is missing one (the no-ref intake
        // copy folding into a referenced one, or vice-versa).
        $fill = [];
        if (blank($keep->external_reference) && filled($dupe->external_reference)) {
            $fill['external_reference'] = $dupe->external_reference;
        }

        // Fill any blank fields on the survivor from the duplicate.
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
