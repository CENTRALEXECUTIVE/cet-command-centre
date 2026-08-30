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
            ->where(fn ($q) => $q
                ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                ->orWhereNotNull('meta->cancellation->fee')) // charged cancellations still pay the driver
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

        // A custom date range (from+to) wins; otherwise a whole month (default).
        $tz = config('app.timezone');
        $from = $request->query('from');
        $to = $request->query('to');
        if ($from && $to) {
            try {
                $start = Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', $to, $tz)->endOfDay();
            } catch (\Throwable) {
                $start = now($tz)->startOfMonth();
                $end = $start->copy()->endOfMonth();
            }
            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
            $rangeLabel = $start->format('d M Y').' – '.$end->format('d M Y');
            $periodParam = ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
        } else {
            $month = $request->query('month');
            $start = ($month ? Carbon::createFromFormat('Y-m', $month, $tz) : now())->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $rangeLabel = $start->format('F Y');
            $periodParam = ['month' => $start->format('Y-m')];
        }

        $bookings = Booking::with(['driver.driverProfile.defaultVehicle', 'customer', 'airport'])
            ->whereBetween('pickup_at', [$start, $end])
            ->where(fn ($q) => $q
                ->whereNotIn('status', [BookingStatus::Cancelled->value])
                ->orWhereNotNull('meta->cancellation->fee')) // charged cancellations still pay the driver
            ->orderBy('pickup_at')
            ->get();

        // A job is relevant to payroll if it has pay set OR carries a tip.
        $relevant = $bookings->filter(fn (Booking $b) => $b->driverPay() !== null || $b->tipsTotal() > 0);

        // ONE row per driver NAME. A driver who also runs an extra car on a
        // multi-car job is NOT split into a separate "extra car" section — their
        // extra-car pay is folded into their own named row, so each person shows
        // once with everything they're owed.
        $blank = fn (string $name) => [
            'name' => $name, 'driver_id' => null, 'phone' => null, 'reg' => null,
            'jobs' => collect(), 'car_jobs' => collect(),
            'pay' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'tips' => 0.0, 'card_tips_owed' => 0.0,
        ];

        $rows = [];
        // Group by the PAYEE — so a job whose fee is routed to Kash lands in
        // Kash's card, not the driver who actually drove it.
        foreach ($relevant->groupBy(fn (Booking $b) => $b->payrollPayeeName()) as $name => $jobs) {
            // Link the card to a driver directory by the card NAME (the payee),
            // so a payee card points at the payee — not the sub-driver.
            $byName = \App\Models\User::where('role', \App\Enums\UserRole::Driver->value)
                ->where('name', $name)->with('driverProfile.defaultVehicle')->first();
            $leadDriver = $byName ?: $jobs->pluck('driver')->filter()->first();
            $rows[$name] = array_merge($blank($name), [
                'driver_id' => $leadDriver?->id,
                'phone' => $leadDriver?->phone,
                // The payee/driver's reg (their default vehicle), else the first job's.
                'reg' => ($byName?->driverProfile?->defaultVehicle?->registration
                    ? strtoupper($byName->driverProfile->defaultVehicle->registration)
                    : $jobs->map->driverVehicleReg()->filter()->first()),
                'jobs' => $jobs->values(),
                'pay' => round($jobs->sum(fn (Booking $b) => $b->driverPay()), 2),
                'paid' => round($jobs->sum(fn (Booking $b) => $b->driverPaidAmount()), 2),
                'remaining' => round($jobs->sum(fn (Booking $b) => $b->driverPayRemaining() ?? 0), 2),
                'tips' => round($jobs->sum(fn (Booking $b) => $b->tipsTotal()), 2),
                'card_tips_owed' => round($jobs->sum(fn (Booking $b) => $b->cardTipsOwed()), 2),
            ]);
        }

        // Extra cars → fold into the same-named driver's row (create one if the
        // driver only ran extra cars this month).
        foreach ($bookings as $b) {
            foreach ($b->extraDrivers() as $d) {
                if (($d['pay'] ?? null) === null) {
                    continue; // no pay figure set yet
                }
                $name = $d['name'] ?? 'Extra driver';
                $rows[$name] ??= $blank($name);
                $pay = (float) ($d['pay'] ?? 0);
                $paid = (float) ($d['paid'] ?? 0);
                $rows[$name]['car_jobs'] = $rows[$name]['car_jobs']->push([
                    'booking' => $b, 'entry' => $d, 'car' => $b->extraDriverCarNumber($d['token'] ?? ''),
                ]);
                $rows[$name]['pay'] = round($rows[$name]['pay'] + $pay, 2);
                $rows[$name]['paid'] = round($rows[$name]['paid'] + $paid, 2);
                $rows[$name]['remaining'] = round($rows[$name]['remaining'] + max(0, $pay - $paid), 2);
                // Best-effort link to the driver's directory when they only ran
                // extra cars (no lead job to borrow the id from).
                if (! $rows[$name]['driver_id']) {
                    $match = \App\Models\User::where('role', \App\Enums\UserRole::Driver->value)
                        ->where('name', $name)->with('driverProfile.defaultVehicle')->first();
                    $rows[$name]['driver_id'] = $match?->id;
                    $rows[$name]['phone'] = $rows[$name]['phone'] ?: $match?->phone;
                    $matchReg = $match?->driverProfile?->defaultVehicle?->registration;
                    $rows[$name]['reg'] = $rows[$name]['reg']
                        ?: (strtoupper(trim((string) ($matchReg ?: ($d['reg'] ?? '')))) ?: null);
                }
            }
        }

        $drivers = collect(array_values($rows))->sortByDesc('remaining')->values();

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
            'rangeLabel' => $rangeLabel,
            'periodParam' => $periodParam,
            'drivers' => $shown,
            'filter' => $filter,
            'missingPay' => $missingPay,
            'totals' => $totals,
            'completedCount' => $completedCount,
            'paidCount' => $paidCount,
        ]);
    }
}
