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

        $withPay = $bookings->filter(fn (Booking $b) => $b->driverPay() !== null);

        // Per-driver totals: pay, paid, remaining, and their jobs for the drill-down.
        $drivers = $withPay
            ->groupBy(fn (Booking $b) => $b->payrollDriverName())
            ->map(fn ($jobs, $name) => [
                'name' => $name,
                'jobs' => $jobs->values(),
                'pay' => round($jobs->sum(fn (Booking $b) => $b->driverPay()), 2),
                'paid' => round($jobs->sum(fn (Booking $b) => $b->driverPaidAmount()), 2),
                'remaining' => round($jobs->sum(fn (Booking $b) => $b->driverPayRemaining() ?? 0), 2),
            ])
            ->sortByDesc('remaining')
            ->values();

        // Completed driver jobs with no pay set — the "don't forget these" list.
        $missingPay = $bookings
            ->filter(fn (Booking $b) => $b->driverPay() === null
                && $b->status === BookingStatus::Complete
                && ($b->driver_id || isset($b->meta['driver_details']['name'])))
            ->values();

        return view('admin.payroll.index', [
            'month' => $start,
            'drivers' => $drivers,
            'missingPay' => $missingPay,
            'totals' => [
                'pay' => round($drivers->sum('pay'), 2),
                'paid' => round($drivers->sum('paid'), 2),
                'remaining' => round($drivers->sum('remaining'), 2),
            ],
        ]);
    }
}
