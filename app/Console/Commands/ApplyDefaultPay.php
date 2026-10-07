<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Set every NOT-YET-DONE booking's driver pay to the standard 90% of the fare,
 * automatically — no "Confirm pay" click needed. Runs on the schedule so:
 *   - all current upcoming/pending jobs get their pay set, and
 *   - any future booking gets it set within minutes of landing (and the moment
 *     it's allocated, via RotationService/BookingStatusService) — hands-off.
 *
 * It is DELIBERATELY safe and never regresses the locked-in money rules:
 *   - never overwrites a pay already on the job (a minibus / V-Class cover job
 *     carries its own offered price — applyDefaultDriverPay guards this),
 *   - skips terminal jobs (completed / cancelled / no-show — already "done"),
 *   - leaves a voucher-discounted job blank for the office to set by hand, so a
 *     driver is never auto-underpaid on 90% of a reduced fare
 *     (suggestedDriverPay returns null — the company absorbs the goodwill).
 *
 * The 90% share is the `driver_pay_percent` setting (change it there to change
 * the default for everything). The office can still edit any single job's pay.
 *
 * Usage:  php artisan cet:apply-default-pay            (apply)
 *         php artisan cet:apply-default-pay --dry-run  (report only)
 */
class ApplyDefaultPay extends Command
{
    protected $signature = 'cet:apply-default-pay {--dry-run : show what would change without saving}';

    protected $description = 'Set driver pay to the standard 90% of fare on every not-yet-done booking';

    public function handle(): int
    {
        $terminal = [BookingStatus::Complete->value, BookingStatus::Cancelled->value, BookingStatus::NoShow->value];

        $bookings = Booking::whereNotIn('status', $terminal)->get();

        $set = 0;
        $alreadySet = 0;
        $leftBlank = 0; // no fare yet, or a discounted job the office sets by hand
        $totalPay = 0.0;

        foreach ($bookings as $booking) {
            if ($booking->driverPay() !== null) {
                $alreadySet++;

                continue;
            }

            $suggested = $booking->suggestedDriverPay();
            if ($suggested === null) {
                $leftBlank++; // no usable fare, or a voucher-discounted job (set by hand)

                continue;
            }

            if (! $this->option('dry-run')) {
                $booking->applyDefaultDriverPay();
            }
            $set++;
            $totalPay += $suggested;
        }

        $verb = $this->option('dry-run') ? 'Would set' : 'Set';
        $this->info("{$verb} 90% driver pay on {$set} booking(s), total £".number_format($totalPay, 2).'.');
        if ($alreadySet > 0) {
            $this->line("{$alreadySet} already had a pay set — left untouched.");
        }
        if ($leftBlank > 0) {
            $this->line("{$leftBlank} left blank (no fare yet, or a discounted job for the office to set by hand).");
        }

        return self::SUCCESS;
    }
}
