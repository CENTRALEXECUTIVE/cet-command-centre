<?php

namespace App\Console\Commands;

use App\Models\BookingTip;
use Illuminate\Console\Command;

/**
 * Removes tips that were attributed from a Square payment. Until the webhook was
 * fixed to only record OUR tip checkouts, any Square payment for a booking (the
 * fare itself) could be logged as a tip and land on a driver's payroll. This
 * clears those Square-sourced entries. Manual / cash tips (logged by the office)
 * are left untouched.
 *
 *   php artisan cet:clear-square-tips --dry-run   # preview, deletes nothing
 *   php artisan cet:clear-square-tips             # remove them
 */
class ClearSquareTips extends Command
{
    protected $signature = 'cet:clear-square-tips {--dry-run : List what would be removed without deleting anything}';

    protected $description = 'Remove Square-attributed tips (fares mis-logged as tips); keeps manual/cash tips';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $tips = BookingTip::whereNotNull('square_payment_id')
            ->with('booking:id,reference,external_reference')
            ->get();

        if ($tips->isEmpty()) {
            $this->info('No Square-attributed tips found.');

            return self::SUCCESS;
        }

        $total = 0.0;
        foreach ($tips as $tip) {
            $ref = $tip->booking?->external_reference ?: ($tip->booking?->reference ?? 'unknown');
            $this->line(($dry ? '  WOULD REMOVE ' : '  removed ')
                .'£'.number_format((float) $tip->amount, 2)." tip on {$ref} (payment {$tip->square_payment_id})");
            $total += (float) $tip->amount;
            if (! $dry) {
                $tip->delete();
            }
        }

        $sum = '£'.number_format($total, 2);
        $this->info($dry
            ? "{$tips->count()} Square-attributed tip(s) totalling {$sum} found. Re-run without --dry-run to remove them."
            : "Removed {$tips->count()} Square-attributed tip(s) totalling {$sum}. Manual/cash tips were kept.");

        return self::SUCCESS;
    }
}
