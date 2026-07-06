<?php

namespace App\Services\Reporting;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revenue & operations reporting. All figures are drawn from COMPLETED bookings,
 * valuing each at final_price (falling back to quoted_price). Revenue is the
 * SQL-portable expression COALESCE(final_price, quoted_price, 0).
 */
class ReportService
{
    private const REVENUE = 'COALESCE(final_price, quoted_price, 0)';

    /**
     * Revenue-earning jobs in the period: every booking whose pickup falls in the
     * window and that WASN'T cancelled or a no-show. We deliberately don't require
     * status = Complete — operators rarely hand-mark jobs complete, so a job that
     * has run (pickup in the past, not cancelled) counts toward revenue.
     */
    private function completed(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Booking::query()
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->where('pickup_at', '<=', now()) // revenue is earned only once a job has run
            ->whereBetween('pickup_at', [$start, $end]);
    }

    /** Non-cancelled jobs in the period, INCLUDING future ones (for payment split). */
    private function scheduled(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Booking::query()
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('pickup_at', [$start, $end]);
    }

    /** @return array{revenue: float, jobs: int, average_fare: float} */
    public function summary(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = $this->completed($start, $end);
        $jobs = (clone $base)->count();
        $revenue = (float) (clone $base)->sum(DB::raw(self::REVENUE));

        return [
            'revenue' => round($revenue, 2),
            'jobs' => $jobs,
            'average_fare' => $jobs ? round($revenue / $jobs, 2) : 0.0,
        ];
    }

    /**
     * Reserved (booked) figures: every non-cancelled job in the period INCLUDING
     * upcoming ones — the full value of business on the books, vs summary()'s
     * earned figure which only counts jobs that have already run.
     *
     * @return array{revenue: float, jobs: int}
     */
    public function reservedSummary(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = $this->scheduled($start, $end);

        return [
            'revenue' => round((float) (clone $base)->sum(DB::raw(self::REVENUE)), 2),
            'jobs' => (clone $base)->count(),
        ];
    }

    /** Earnings and job count per driver. */
    public function earningsByDriver(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return $this->completed($start, $end)
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('driver_id')
            ->with('driver:id,name')
            ->orderByDesc('revenue')
            ->get();
    }

    /** Bookings and revenue per vehicle type. */
    public function byVehicleType(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return $this->completed($start, $end)
            ->selectRaw('vehicle_type_id, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('vehicle_type_id')
            ->with('vehicleType:id,name')
            ->orderByDesc('jobs')
            ->get();
    }

    /** Top customers by revenue. */
    public function topCustomers(CarbonInterface $start, CarbonInterface $end, int $limit = 10): Collection
    {
        return $this->completed($start, $end)
            ->selectRaw('customer_id, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('customer_id')
            ->with('customer:id,name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    /** Top routes by volume (pickup → destination). */
    public function topRoutes(CarbonInterface $start, CarbonInterface $end, int $limit = 10): Collection
    {
        return $this->completed($start, $end)
            ->selectRaw('pickup_address, destination_address, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('pickup_address', 'destination_address')
            ->orderByDesc('jobs')
            ->limit($limit)
            ->get();
    }

    /**
     * Revenue and job count per calendar month across the period, in date order.
     * Grouped in PHP so it's portable across MySQL and SQLite.
     */
    public function monthlyRevenue(CarbonInterface $start, CarbonInterface $end): Collection
    {
        $now = now();

        // Pull ALL non-cancelled jobs (incl. upcoming) so each month can show both
        // earned (run) and reserved (booked) figures.
        return $this->scheduled($start, $end)
            ->selectRaw('pickup_at, '.self::REVENUE.' as revenue')
            ->get()
            ->groupBy(fn ($b) => $b->pickup_at->format('Y-m'))
            ->map(function ($group, $ym) use ($now) {
                $earned = $group->filter(fn ($b) => $b->pickup_at->lte($now));

                return [
                    'month' => $ym,
                    'label' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('M Y'),
                    'jobs' => $earned->count(),
                    'revenue' => round((float) $earned->sum('revenue'), 2),
                    'booked_jobs' => $group->count(),
                    'booked_revenue' => round((float) $group->sum('revenue'), 2),
                ];
            })
            ->sortKeys()
            ->values();
    }

    /**
     * A transparency breakdown of what's actually being counted in the period, so
     * the headline job/revenue figures can be sanity-checked: how many counted
     * jobs, how many are return legs (a return is two legs), how many have no
     * fare, how many share a booking reference (possible duplicates), and how
     * many were excluded as cancelled/no-show.
     *
     * @return array<string, int>
     */
    public function dataHealth(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = $this->completed($start, $end);

        $noPrice = (clone $base)
            ->where(fn ($q) => $q->whereNull('final_price')->orWhere('final_price', '<=', 0))
            ->where(fn ($q) => $q->whereNull('quoted_price')->orWhere('quoted_price', '<=', 0))
            ->count();

        // Booking references that appear more than once in the window.
        $dupeRefs = Booking::query()
            ->whereBetween('pickup_at', [$start, $end])
            ->whereNotNull('external_reference')
            ->where('external_reference', '!=', '')
            ->groupBy('external_reference')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('external_reference');

        return [
            'jobs' => (clone $base)->count(),
            'return_legs' => (clone $base)->where('is_return_leg', true)->count(),
            'no_price' => $noPrice,
            'duplicate_refs' => $dupeRefs->count(),
            'excluded' => Booking::whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                ->whereBetween('pickup_at', [$start, $end])->count(),
        ];
    }

    /** Cancellations/no-shows in the period, and the cancellation rate. */
    public function cancellations(CarbonInterface $start, CarbonInterface $end): array
    {
        $cancelled = Booking::query()
            ->whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('pickup_at', [$start, $end])
            ->count();
        $ran = $this->completed($start, $end)->count();
        $total = $cancelled + $ran;

        return [
            'cancelled' => $cancelled,
            'total' => $total,
            'rate_pct' => $total ? round(($cancelled / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Money collected vs genuinely still owed for the period.
     *
     * A job counts as COLLECTED if it's marked paid OR its pickup has already
     * passed — because a job that has run has been paid (card up front, cash
     * taken by the driver on the day). "Pending" only means money still to come
     * on a FUTURE job (a cash fare the driver will collect on the pickup day), so
     * OUTSTANDING is limited to not-yet-run, unpaid jobs.
     *
     * @return array{collected: float, outstanding: float}
     */
    public function paymentSplit(CarbonInterface $start, CarbonInterface $end): array
    {
        $now = now();
        $base = $this->scheduled($start, $end); // include future jobs here

        $collected = (float) (clone $base)
            ->where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('pickup_at', '<', $now))
            ->sum(DB::raw(self::REVENUE));

        // Unpaid AND still in the future = a cash fare yet to be collected.
        $outstanding = (float) (clone $base)
            ->where('payment_status', '!=', 'paid')
            ->where('pickup_at', '>=', $now)
            ->sum(DB::raw(self::REVENUE));

        return ['collected' => round($collected, 2), 'outstanding' => round($outstanding, 2)];
    }

    /** Compare a period against the immediately preceding equal-length period. */
    public function comparison(CarbonInterface $start, CarbonInterface $end): array
    {
        $length = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($length - 1)->startOfDay();

        $current = $this->summary($start, $end);
        $previous = $this->summary($prevStart, $prevEnd);

        return [
            'current' => $current,
            'previous' => $previous,
            'revenue_change_pct' => $this->pctChange($previous['revenue'], $current['revenue']),
            'jobs_change_pct' => $this->pctChange($previous['jobs'], $current['jobs']),
        ];
    }

    private function pctChange(float $from, float $to): ?float
    {
        if ($from == 0.0) {
            return $to > 0 ? 100.0 : null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }
}
