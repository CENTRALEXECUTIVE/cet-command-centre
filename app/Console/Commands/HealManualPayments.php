<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\WatchdogEvent;
use Illuminate\Console\Command;

/**
 * One-off repair: a booking marked paid by hand and then ALSO recorded from
 * Stripe/Square (e.g. by cet:reconcile-payments) counted the same money twice
 * (£360 + £360) — reading as a double payment and re-sending the paid emails.
 * This folds each manual placeholder into the real payment that confirms it,
 * fixes the paid total, and clears the false "possible double payment" alert.
 * Safe to run more than once; sends nothing.
 */
class HealManualPayments extends Command
{
    protected $signature = 'cet:heal-manual-payments {--dry : Report only}';

    protected $description = 'Merge hand "Mark paid" entries into the real card payment that confirms them';

    public function handle(): int
    {
        $fixed = 0;
        Booking::query()->whereNotNull('meta->square_payment')->get()->each(function (Booking $b) use (&$fixed) {
            $meta = $b->meta ?? [];
            $history = $meta['square_payment']['history'] ?? [];
            $ids = array_column($history, 'id');
            $hasManual = (bool) array_filter($ids, fn ($id) => str_starts_with((string) $id, 'MANUAL-'));
            $hasReal = (bool) array_filter($ids, fn ($id) => ! str_starts_with((string) $id, 'MANUAL-'));
            if (! $hasManual || ! $hasReal) {
                return;
            }

            $before = (float) ($meta['square_payment']['amount'] ?? 0);
            $clean = Booking::absorbManualPayments($history, $meta);
            if (count($clean) === count($history)) {
                return; // nothing absorbed
            }
            $after = round(array_sum(array_map(fn ($h) => (float) ($h['amount'] ?? 0), $clean)), 2);
            $this->line(($b->external_reference ?: $b->reference).": £{$before} → £{$after}");
            $fixed++;
            if ($this->option('dry')) {
                return;
            }

            $latest = end($clean);
            $meta['square_payment'] = array_merge(
                array_intersect_key($latest, array_flip(['id', 'amount', 'at', 'entity'])),
                ['history' => $clean, 'amount' => $after],
            );
            $b->forceFill(['meta' => $meta])->save();

            WatchdogEvent::where('booking_id', $b->id)->where('event_type', 'possible_double_payment')
                ->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
        });

        $this->info($fixed ? "Repaired {$fixed} booking(s)." : 'Nothing to repair.');

        return self::SUCCESS;
    }
}
