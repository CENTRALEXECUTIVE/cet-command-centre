<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Restores driver pay that the Outlook ingest wiped from meta['payroll'] before
 * that bug was fixed. Every time the office set pay, the activity log captured
 * the new meta — so the amounts still exist in audit_logs even though the
 * booking's own copy was overwritten. This reads the LATEST audited payroll for
 * each booking and puts it back, but only on bookings that currently have NO pay
 * set (so anything already re-entered by hand is never clobbered).
 *
 *   php artisan cet:recover-payroll --dry-run   # preview, changes nothing
 *   php artisan cet:recover-payroll             # restore it
 */
class RecoverPayroll extends Command
{
    protected $signature = 'cet:recover-payroll {--dry-run : List what would be restored without changing anything}';

    protected $description = 'Restore driver pay wiped by the old Outlook ingest, from the activity log';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Oldest → newest so the LAST payroll we saw for a booking wins.
        $rows = AuditLog::where('auditable_type', Booking::class)
            ->where('new_values', 'like', '%payroll%')
            ->orderBy('id')
            ->get(['auditable_id', 'new_values']);

        $latest = [];
        foreach ($rows as $row) {
            // meta may be stored as a nested array OR (from getChanges) as a
            // JSON string inside new_values — handle both.
            $meta = data_get($row->new_values, 'meta');
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            $payroll = is_array($meta) ? ($meta['payroll'] ?? null) : null;

            if (is_array($payroll) && array_key_exists('pay', $payroll) && $payroll['pay'] !== null) {
                $latest[$row->auditable_id] = $payroll;
            }
        }

        if ($latest === []) {
            $this->info('No audited driver pay found to recover.');

            return self::SUCCESS;
        }

        $restored = 0;
        $total = 0.0;
        foreach ($latest as $bookingId => $payroll) {
            $booking = Booking::find($bookingId);
            if (! $booking) {
                continue;
            }
            // Never overwrite pay that's currently set (e.g. already re-entered).
            if ($booking->driverPay() !== null) {
                continue;
            }

            $ref = $booking->external_reference ?: $booking->reference;
            $pay = (float) $payroll['pay'];
            $paid = (float) ($payroll['paid'] ?? 0);
            $this->line(($dry ? '  WOULD RESTORE ' : '  restored ')
                ."£{$pay} pay (£{$paid} paid) on {$ref} — {$booking->payrollDriverName()}");
            $total += $pay;
            $restored++;

            if (! $dry) {
                $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => $payroll])])->save();
            }
        }

        $sum = '£'.number_format($total, 2);
        $this->info($dry
            ? "{$restored} booking(s) with recoverable pay totalling {$sum}. Re-run without --dry-run to restore."
            : "Restored driver pay on {$restored} booking(s), totalling {$sum}.");

        return self::SUCCESS;
    }
}
