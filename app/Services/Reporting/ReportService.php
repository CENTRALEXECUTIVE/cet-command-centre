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
