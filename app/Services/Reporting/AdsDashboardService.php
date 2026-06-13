<?php

namespace App\Services\Reporting;

use App\Models\AdMetric;
use Carbon\CarbonInterface;

/**
 * Google Ads performance dashboard: live ROAS, spend vs revenue, and the budget
 * trigger alerts (40 conversions, 100 jobs, £14,000 revenue). Ad spend /
 * conversions come from ad_metrics (synced from Google Ads); jobs and revenue
 * are the actual completed-booking figures from the ReportService.
 */
class AdsDashboardService
{
    public function __construct(private readonly ReportService $reports) {}

    public function forPeriod(CarbonInterface $start, CarbonInterface $end): array
    {
        $ads = AdMetric::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
        $summary = $this->reports->summary($start, $end);

        $spend = (float) $ads->sum('spend');
        $conversions = (int) $ads->sum('conversions');
        $clicks = (int) $ads->sum('clicks');
        $impressions = (int) $ads->sum('impressions');
        $revenue = (float) $summary['revenue'];
        $jobs = (int) $summary['jobs'];

        $thresholds = config('cet.ads_alert_thresholds');

        return [
            'spend' => round($spend, 2),
            'revenue' => $revenue,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            'conversions' => $conversions,
            'jobs' => $jobs,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'cost_per_conversion' => $conversions > 0 ? round($spend / $conversions, 2) : null,
            'alerts' => $this->alerts($conversions, $jobs, $revenue, $thresholds),
            'thresholds' => $thresholds,
        ];
    }

    /**
     * Budget trigger alerts: fire when a metric reaches its threshold.
     *
     * @return array<int, array{metric: string, value: int|float, threshold: int|float}>
     */
    private function alerts(int $conversions, int $jobs, float $revenue, array $thresholds): array
    {
        $alerts = [];

        if ($conversions >= $thresholds['conversions']) {
            $alerts[] = ['metric' => 'Conversions', 'value' => $conversions, 'threshold' => $thresholds['conversions']];
        }
        if ($jobs >= $thresholds['jobs']) {
            $alerts[] = ['metric' => 'Jobs', 'value' => $jobs, 'threshold' => $thresholds['jobs']];
        }
        if ($revenue >= $thresholds['revenue']) {
            $alerts[] = ['metric' => 'Revenue', 'value' => $revenue, 'threshold' => $thresholds['revenue']];
        }

        return $alerts;
    }
}
