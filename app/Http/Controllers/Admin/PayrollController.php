<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Driver payroll overview (admin): for a month, every job's driver pay grouped
 * per driver — total pay, what's been handed over, and what's still owed — plus
 * completed jobs that don't have a pay amount set yet so nothing is missed.
 * All figures come from each booking's payroll block (set on the booking page).
 */
class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $month = $request->query('month');
        $start = ($month ? Carbon::createFromFormat('Y-m', $month, config('app.timezone')) : now())->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $bookings = Booking::with(['driver', 'customer'])
            ->whereBetween('pickup_at', [$start, $end])
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->orderBy('pickup_at')
            ->get();

        // A job is relevant to payroll if it has pay set OR carries a tip.
        $relevant = $bookings->filter(fn (Booking $b) => $b->driverPay() !== null || $b->tipsTotal() > 0);

        // Per-driver totals: pay, paid, remaining, tips, and their jobs for the drill-down.
        $drivers = $relevant
            ->groupBy(fn (Booking $b) => $b->payrollDriverName())
            ->map(fn ($jobs, $name) => [
                'name' => $name,
                'jobs' => $jobs->values(),
                'pay' => round($jobs->sum(fn (Booking $b) => $b->driverPay()), 2),
                'paid' => round($jobs->sum(fn (Booking $b) => $b->driverPaidAmount()), 2),
                'remaining' => round($jobs->sum(fn (Booking $b) => $b->driverPayRemaining() ?? 0), 2),
                'tips' => round($jobs->sum(fn (Booking $b) => $b->tipsTotal()), 2),
                'card_tips_owed' => round($jobs->sum(fn (Booking $b) => $b->cardTipsOwed()), 2),
            ])
            ->sortByDesc('remaining')
            ->values();

        // Totals come from ALL drivers (the tiles always show the full picture).
        $totals = [
            'pay' => round($drivers->sum('pay'), 2),
            'paid' => round($drivers->sum('paid'), 2),
            'remaining' => round($drivers->sum('remaining'), 2),
            'tips' => round($drivers->sum('tips'), 2),
            'card_tips_owed' => round($drivers->sum('card_tips_owed'), 2),
        ];

        // Tapping a tile filters the driver list to that category.
        $filter = in_array($request->query('filter'), ['paid', 'owed', 'tips'], true)
            ? $request->query('filter') : 'all';
        $shown = match ($filter) {
            'paid' => $drivers->filter(fn ($d) => $d['paid'] > 0)->values(),
            'owed' => $drivers->filter(fn ($d) => $d['remaining'] > 0)->values(),
            'tips' => $drivers->filter(fn ($d) => $d['tips'] > 0)->values(),
            default => $drivers,
        };

        // Jobs that have RUN with no pay set yet — the "don't forget these" list.
        // Uses the same "has run" rule as the completed count (pickup passed, not
        // cancelled/no-show) rather than status === Complete, because the office
        // works off the calendar and rarely hand-marks a job Complete — so this
        // now surfaces EVERY job still needing a pay amount, matching "still to pay".
        $missingPay = $bookings
            ->filter(fn (Booking $b) => $b->driverPay() === null
                && $b->status !== BookingStatus::NoShow
                && $b->pickup_at && $b->pickup_at->lte(now())
                && ($b->driver_id || isset($b->meta['driver_details']['name'])))
            ->sortBy('pickup_at')
            ->values();

        // Month coverage: jobs that have actually RUN this month (completed) —
        // the SAME definition the Review page uses: non-cancelled, non-no-show,
        // pickup already passed. Then how many of those the driver's been PAID IN
        // FULL. Upcoming jobs don't count — you pay a driver after the job runs.
        $completed = $bookings->filter(fn (Booking $b) => $b->status !== BookingStatus::NoShow
            && $b->pickup_at && $b->pickup_at->lte(now()));
        $completedCount = $completed->count();
        $paidCount = $completed
            ->filter(fn (Booking $b) => $b->driverPay() !== null && ($b->driverPayRemaining() ?? 1) <= 0)
            ->count();

        return view('admin.payroll.index', [
            'month' => $start,
            'drivers' => $shown,
            'filter' => $filter,
            'missingPay' => $missingPay,
            'totals' => $totals,
            'completedCount' => $completedCount,
            'paidCount' => $paidCount,
        ]);
    }
}
