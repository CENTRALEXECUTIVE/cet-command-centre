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
