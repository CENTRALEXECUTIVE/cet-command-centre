<?php

namespace App\Http\Controllers;

use App\Services\Reporting\AdsDashboardService;
use App\Services\Reporting\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Revenue & marketing reports (admin): earnings by driver, top routes, bookings
 * by vehicle type, period comparison, and the Google Ads dashboard.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AdsDashboardService $ads,
    ) {}

    public function revenue(Request $request): View
    {
        [$start, $end] = $this->range($request);

        return view('reports.revenue', [
            'start' => $start,
            'end' => $end,
            'comparison' => $this->reports->comparison($start, $end),
            'byDriver' => $this->reports->earningsByDriver($start, $end),
            'byVehicle' => $this->reports->byVehicleType($start, $end),
            'topRoutes' => $this->reports->topRoutes($start, $end),
        ]);
    }

    public function profit(Request $request): View
    {
        // A custom date RANGE wins (so you can see profit for any period), else a
        // whole month, else the current month. Range is clamped start ≤ end.
        $month = $request->query('month');
        $startQ = $request->date('start');
        $endQ = $request->date('end');

        if ($startQ || $endQ) {
            $start = ($startQ ?? $endQ)->copy()->startOfDay();
            $end = ($endQ ?? $startQ)->copy()->endOfDay();
            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
            $isRange = true;
        } else {
            $start = ($month ? Carbon::createFromFormat('Y-m', $month, config('app.timezone')) : now())->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $isRange = false;
        }

        return view('reports.profit', [
            'month' => $start,
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'isRange' => $isRange,
            'data' => $this->reports->profit($start, $end),
            'trend' => $this->reports->profitTrend(12),
        ]);
    }

    /**
     * Money owed — what payroll still has to pay drivers and what's outstanding on
     * corporate-account invoices, for a date range (default: this month to date).
     */
    public function owed(Request $request): View
    {
        $startQ = $request->date('start');
        $endQ = $request->date('end');
        $start = ($startQ ?: now()->startOfMonth())->copy()->startOfDay();
        $end = ($endQ ?: now())->copy()->endOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return view('reports.owed', [
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'data' => $this->reports->moneyOwed($start, $end),
        ]);
    }

    /** One business in detail — its customers, repeat clients and spend. */
    public function business(Request $request, \App\Models\CorporateAccount $account): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $customers = $this->reports->businessCustomers($account->id);

        return view('reports.business', [
            'account' => $account,
            'customers' => $customers,
            'totals' => [
                'bookings' => $customers->sum('bookings'),
                'revenue' => round($customers->sum('revenue'), 2),
                'customers' => $customers->count(),
                'repeat_customers' => $customers->where('repeat', true)->count(),
            ],
        ]);
    }

    public function ads(Request $request): View
    {
        [$start, $end] = $this->range($request);

        return view('reports.ads', [
            'start' => $start,
            'end' => $end,
            'data' => $this->ads->forPeriod($start, $end),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $start = ($request->date('start') ?? now()->startOfMonth())->startOfDay();
        $end = ($request->date('end') ?? now())->endOfDay();

        return [$start, $end];
    }
}
