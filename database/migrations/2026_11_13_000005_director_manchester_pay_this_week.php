<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Directors, 13 Nov 2026: Abdi's and Maj's Manchester Airport jobs (to or from MAN,
 * executive saloon) now pay a flat £95 after commission. Apply it to THIS WEEK's jobs
 * too (Mon 9 – Sun 15 Nov 2026, UK time). Cancelled/no-show jobs are skipped. New jobs
 * get it automatically (Booking::suggestedDriverPay). No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('bookings')) {
            return;
        }
        $tz = config('app.timezone', 'Europe/London');
        $from = Carbon::parse('2026-11-09 00:00', $tz)->utc();
        $to = Carbon::parse('2026-11-16 00:00', $tz)->utc();

        Booking::with(['driver.driverProfile', 'vehicleType', 'airport'])
            ->whereBetween('pickup_at', [$from, $to])
            ->whereNotNull('driver_id')
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get()
            ->filter(fn (Booking $b) => $b->isDirectorManchesterJob())
            ->each(function (Booking $b) {
                $payroll = $b->meta['payroll'] ?? [];
                $old = $payroll['pay'] ?? null;
                $payroll['pay'] = $b->directorManchesterPay();
                $payroll['pay_auto'] = true;
                $payroll['pay_note'] = 'Directors\' Manchester rate (13 Nov 2026)'.($old !== null ? '; was £'.number_format((float) $old, 2) : '');
                $b->forceFill(['meta' => array_merge($b->meta ?? [], ['payroll' => $payroll])])->saveQuietly();
            });
    }

    public function down(): void
    {
    }
};
