<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Backfills the fare into the money columns (quoted_price / final_price) for
 * bookings that have neither set — so the Review page and reports show real
 * revenue. The fare is taken from meta['total_amount'] (live email feed) or,
 * failing that, parsed from the calendar event's "Payment: … £X" line (imports).
 *
 * Never touches the calendar. Only fills empty price columns; never overwrites.
 *
 * Usage:  php artisan cet:backfill-prices            (apply)
 *         php artisan cet:backfill-prices --dry-run  (report only)
 */
class BackfillPrices extends Command
{
    protected $signature = 'cet:backfill-prices {--dry-run : show what would change without saving}';

    protected $description = 'Fill booking prices from meta/calendar so revenue reporting works';

    public function handle(): int
    {
        // Any booking with no USABLE fare — both money columns null or ≤ 0.
        $noPrice = fn ($q) => $q->whereNull('quoted_price')->orWhere('quoted_price', '<=', 0);
        $noFinal = fn ($q) => $q->whereNull('final_price')->orWhere('final_price', '<=', 0);

        $bookings = Booking::where($noPrice)->where($noFinal)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->with('calendarEvent')
            ->get();

        $filled = 0;
        $unrecoverable = 0;
        $totalValue = 0.0;
        foreach ($bookings as $booking) {
            [$fare, $fullyPaid] = $this->fareFor($booking);
            if ($fare === null || $fare <= 0) {
                $unrecoverable++; // no price anywhere in the system for this job
                continue;
            }

            if (! $this->option('dry-run')) {
                $booking->forceFill([
                    'quoted_price' => $fare,
                    'final_price' => $fullyPaid ? $fare : null,
                ])->save();
            }
            $filled++;
            $totalValue += $fare;
        }

        $verb = $this->option('dry-run') ? 'Would fill' : 'Filled';
        $this->info("{$verb} prices on {$filled} booking(s), total fare value £".number_format($totalValue, 2).'.');
        if ($unrecoverable > 0) {
            $this->warn("{$unrecoverable} booking(s) still have NO price anywhere in the system — "
                .'import the ETO export covering those dates to fill them (it updates existing bookings by reference).');
        }

        return self::SUCCESS;
    }

    /**
     * The fare and whether it's fully paid — from meta['total_amount'] first,
     * else summed from the calendar event's Payment line.
     *
     * @return array{0: float|null, 1: bool}
     */
    private function fareFor(Booking $booking): array
    {
        $metaTotal = (float) ($booking->meta['total_amount'] ?? 0);
        if ($metaTotal > 0) {
            return [$metaTotal, ($booking->payment_status ?? '') === 'paid'];
        }

        $description = (string) ($booking->calendarEvent->description ?? '');
        if (! preg_match('/Payment:\*?\s*(.+)/', $description, $m)) {
            return [null, false];
        }
        $paymentLine = trim(explode("\n", $m[1])[0]);

        // Sum every £ figure on the line (covers "Paid £150 + Pending £220").
        preg_match_all('/£\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/', $paymentLine, $amounts);
        if ($amounts[1] === []) {
            return [null, false];
        }
        $fare = array_sum(array_map(fn ($a) => (float) str_replace(',', '', $a), $amounts[1]));

        // Fully paid if the line mentions "Paid" and no outstanding balance word.
        $fullyPaid = (bool) preg_match('/\bPaid\b/i', $paymentLine)
            && ! preg_match('/\b(pending|due|outstanding|balance)\b/i', $paymentLine);

        return [$fare, $fullyPaid];
    }
}
