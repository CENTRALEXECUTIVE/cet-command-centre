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
    /**
     * End-of-day cash & pay reconciliation for one day. Shows, per driver, the
     * cash they collected from customers (which they hold), what the job pays
     * them, and the single NET figure that settles up:
     *
     *   net = pay − cash in hand − already paid + card tips owed
     *
     * A positive net is money the business hands the driver; a negative net is
     * cash the driver hands back (they collected more than their pay). So at the
     * end of a shift every driver settles to exactly their earnings.
     */
    public function daily(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $date = $request->query('date');
        $day = ($date ? Carbon::createFromFormat('Y-m-d', $date, config('app.timezone')) : now())->startOfDay();
        $end = $day->copy()->endOfDay();

        $jobs = Booking::with(['driver', 'customer'])
            ->whereBetween('pickup_at', [$day, $end])
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->orderBy('pickup_at')
            ->get();

        $drivers = $jobs
            ->filter(fn (Booking $b) => $b->driver_id || $b->driverPay() !== null || $b->driverSettledByCustomer())
            ->groupBy(fn (Booking $b) => $b->payrollDriverName())
            ->map(function ($group, $name) {
                $pay = round($group->sum(fn (Booking $b) => $b->driverPay() ?? 0), 2);
                $cash = round($group->sum(fn (Booking $b) => $b->driverSettledByCustomer() ? ($b->cashDueToDriver() ?? 0) : 0), 2);
                $paid = round($group->sum(fn (Booking $b) => $b->driverPaidAmount()), 2);
                $cardTips = round($group->sum(fn (Booking $b) => $b->cardTipsOwed()), 2);

                return [
                    'name' => $name,
                    'jobs' => $group->values(),
                    'count' => $group->count(),
                    'pay' => $pay,
                    'cash' => $cash,
                    'card_tips' => $cardTips,
                    'net' => round($pay - $cash - $paid + $cardTips, 2), // + business owes / − driver owes
                ];
            })
            ->sortByDesc('count')->values();

        return view('admin.payroll.daily', [
            'day' => $day,
            'drivers' => $drivers,
            'totals' => [
                'jobs' => $jobs->count(),
                'cash_collected' => round($drivers->sum('cash'), 2),
                'to_pay_out' => round($drivers->sum(fn ($d) => max(0, $d['net'])), 2),
                'to_collect_back' => round($drivers->sum(fn ($d) => max(0, -$d['net'])), 2),
                'card_tips' => round($drivers->sum('card_tips'), 2),
            ],
        ]);
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $month = $request->query('month');
        $start = ($month ? Carbon::createFromFormat('Y-m', $month, config('app.timezone')) : now())->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $bookings = Booking::with(['driver', 'customer', 'airport'])
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
                // The driver's user record (for the clickable name → their
                // directory details), from the first job that has one.
                'driver_id' => $jobs->pluck('driver')->filter()->first()?->id,
                'jobs' => $jobs->values(),
                'pay' => round($jobs->sum(fn (Booking $b) => $b->driverPay()), 2),
                'paid' => round($jobs->sum(fn (Booking $b) => $b->driverPaidAmount()), 2),
                'remaining' => round($jobs->sum(fn (Booking $b) => $b->driverPayRemaining() ?? 0), 2),
                'tips' => round($jobs->sum(fn (Booking $b) => $b->tipsTotal()), 2),
                'card_tips_owed' => round($jobs->sum(fn (Booking $b) => $b->cardTipsOwed()), 2),
            ])
            ->values();

        // Extra cars on multi-car jobs are paid separately — one payroll row per
        // extra driver (grouped by name), independent of the lead driver.
        $extraRows = collect();
        foreach ($bookings as $b) {
            foreach ($b->extraDrivers() as $d) {
                if (($d['pay'] ?? null) === null) {
                    continue; // not relevant to payroll until a pay figure is set
                }
                $extraRows->push(['name' => $d['name'] ?? 'Extra driver', 'booking' => $b, 'entry' => $d, 'car' => $b->extraDriverCarNumber($d['token'] ?? '')]);
            }
        }
        $extraDrivers = $extraRows->groupBy('name')->map(function ($rows, $name) {
            $pay = round($rows->sum(fn ($r) => (float) ($r['entry']['pay'] ?? 0)), 2);
            $paid = round($rows->sum(fn ($r) => (float) ($r['entry']['paid'] ?? 0)), 2);

            return [
                'name' => $name,
                'jobs' => collect(),         // extra rows render their own car table
                'car_jobs' => $rows->values(),
                'extra' => true,
                'pay' => $pay,
                'paid' => $paid,
                'remaining' => round(max(0, $pay - $paid), 2),
                'tips' => 0.0,
                'card_tips_owed' => 0.0,
            ];
        })->values();

        $drivers = $drivers->concat($extraDrivers)->sortByDesc('remaining')->values();

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
        // Every completed job that still needs a pay figure — the "fill these in"
        // list. Cash jobs settle with the driver directly (customer pays them on
        // the day) so they never need a figure and are excluded. We DON'T require
        // a driver to be assigned: pre-Command-Centre imports often have no driver
        // yet, and the office still wants to fill their pay in (they show as
        // "Unassigned" and can be opened to attach a driver).
        $missingPay = $bookings
            ->filter(fn (Booking $b) => $b->driverPay() === null
                && ! $b->driverSettledByCustomer()
                && $b->status !== BookingStatus::NoShow
                && $b->pickup_at && $b->pickup_at->lte(now()))
            ->sortBy('pickup_at')
            ->values();

        // Month coverage: jobs that have actually RUN this month (completed) —
        // the SAME definition the Review page uses: non-cancelled, non-no-show,
        // pickup already passed. Then how many of those the driver's been PAID IN
        // FULL. Upcoming jobs don't count — you pay a driver after the job runs.
        $completed = $bookings->filter(fn (Booking $b) => $b->status !== BookingStatus::NoShow
            && $b->pickup_at && $b->pickup_at->lte(now()));
        $completedCount = $completed->count();
        // A driver is "paid" for a job when it's a cash job (settled with the
        // customer directly) OR pay is set and nothing remains.
        $paidCount = $completed
            ->filter(fn (Booking $b) => $b->driverSettledByCustomer()
                || ($b->driverPay() !== null && ($b->driverPayRemaining() ?? 1) <= 0))
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
