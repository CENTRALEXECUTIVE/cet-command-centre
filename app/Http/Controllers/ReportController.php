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
        // Always a DATE RANGE. A from/to range is used as given; a legacy ?month= is
        // converted to that month's range; otherwise it defaults to the current
        // month shown as a range. No bare-month view.
        $tz = config('app.timezone');
        $startQ = $request->date('start');
        $endQ = $request->date('end');
        $month = $request->query('month');

        if ($startQ || $endQ) {
            $start = ($startQ ?? $endQ)->copy()->startOfDay();
            $end = ($endQ ?? $startQ)->copy()->endOfDay();
        } else {
            $start = ($month ? rescue(fn () => Carbon::createFromFormat('Y-m', $month, $tz), now($tz)) : now($tz))->startOfMonth();
            $end = $start->copy()->endOfMonth();
        }
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return view('reports.profit', [
            'rangeStart' => $start,
            'rangeEnd' => $end,
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

    /**
     * New customers per month vs Google Ads spend — so the office can see how many
     * genuinely new customers each month brought and what the ads cost to win them.
     * Defaults to the last 12 months when no range is given.
     */
    public function customers(Request $request): View
    {
        $start = ($request->date('start') ?? now()->subMonths(11)->startOfMonth())->startOfMonth();
        $end = ($request->date('end') ?? now())->endOfMonth();

        $rows = $this->reports->newCustomersByMonth($start, $end);

        return view('reports.customers', [
            'start' => $start,
            'end' => $end,
            'rows' => $rows,
            'totals' => [
                'new_customers' => array_sum(array_column($rows, 'new_customers')),
                'web' => array_sum(array_column($rows, 'web')),
                'other' => array_sum(array_column($rows, 'other')),
                'ad_spend' => round(array_sum(array_column($rows, 'ad_spend')), 2),
                'ad_conversions' => array_sum(array_column($rows, 'ad_conversions')),
            ],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    /**
     * VAT report — every VAT invoice in the range with net / VAT / gross and
     * whether the VAT has been received, plus totals for the VAT return. ?csv=1
     * downloads it.
     */
    public function vat(Request $request): View|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$start, $end] = $this->range($request);
        $data = $this->reports->vatInvoices($start, $end);

        if ($request->boolean('csv')) {
            $filename = 'VAT-report-'.$start->format('Y-m-d').'-to-'.$end->format('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($data) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Date', 'Reference', 'Company', 'Net £', 'VAT £', 'Gross £', 'VAT status']);
                foreach ($data['rows'] as $r) {
                    fputcsv($out, [
                        $r['date']?->format('Y-m-d H:i'), $r['reference'], $r['company'],
                        number_format($r['net'], 2, '.', ''), number_format($r['vat'], 2, '.', ''),
                        number_format($r['gross'], 2, '.', ''), $r['paid'] ? 'VAT received' : 'Outstanding',
                    ]);
                }
                fputcsv($out, []);
                fputcsv($out, ['Totals', '', '', number_format($data['net'], 2, '.', ''), number_format($data['vat'], 2, '.', ''), number_format($data['gross'], 2, '.', ''), '']);
                fputcsv($out, ['VAT received', '', '', '', number_format($data['vat_received'], 2, '.', ''), '', '']);
                fputcsv($out, ['VAT outstanding', '', '', '', number_format($data['vat_outstanding'], 2, '.', ''), '', '']);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv']);
        }

        return view('reports.vat', ['start' => $start, 'end' => $end, 'data' => $data]);
    }

    private function range(Request $request): array
    {
        $start = ($request->date('start') ?? now()->startOfMonth())->startOfDay();
        $end = ($request->date('end') ?? now())->endOfDay();

        return [$start, $end];
    }
}
