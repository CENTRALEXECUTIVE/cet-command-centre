<?php

namespace App\Services\Reporting;

use App\Models\AdMetric;
use App\Models\Keyword;
use App\Services\Ai\AnthropicService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Google Ads performance dashboard: live ROAS, spend vs revenue, and the budget
 * trigger alerts (40 conversions, 100 jobs, £14,000 revenue). Ad spend /
 * conversions come from ad_metrics (synced from Google Ads); jobs and revenue
 * are the bookings that CAME THROUGH in the period (by booking date), so they
 * line up with the ad spend that generated them.
 */
class AdsDashboardService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AnthropicService $ai,
    ) {}

    public function forPeriod(CarbonInterface $start, CarbonInterface $end): array
    {
        $ads = AdMetric::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
        // Match ad spend to the bookings that CAME THROUGH in this window (by
        // booking date), not by pickup date — that's what the ads generated.
        $summary = $this->reports->createdSummary($start, $end);

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
     * The full Google Ads review: headline metrics, per-campaign breakdown, the
     * top keywords by spend, and a plain-English recommendation (increase /
     * decrease / hold budget + specific actions) — from Claude when available,
     * else a deterministic rule-based verdict.
     *
     * @return array<string, mixed>
     */
    public function analysis(CarbonInterface $start, CarbonInterface $end): array
    {
        $metrics = $this->forPeriod($start, $end);

        $byCampaign = AdMetric::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('campaign, SUM(spend) as spend, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(conversions) as conversions, SUM(revenue) as revenue')
            ->groupBy('campaign')
            ->orderByDesc('spend')
            ->get()
            ->map(fn ($c) => [
                'campaign' => $c->campaign ?: '—',
                'spend' => round((float) $c->spend, 2),
                'clicks' => (int) $c->clicks,
                'impressions' => (int) $c->impressions,
                'conversions' => (int) $c->conversions,
                'ctr' => $c->impressions > 0 ? round($c->clicks / $c->impressions * 100, 2) : null,
                'cpc' => $c->clicks > 0 ? round($c->spend / $c->clicks, 2) : null,
                'cpa' => $c->conversions > 0 ? round($c->spend / $c->conversions, 2) : null,
            ])
            ->all();

        $topKeywords = Keyword::query()
            ->orderByDesc('cost')->limit(10)->get()
            ->map(fn (Keyword $k) => [
                'keyword' => $k->keyword,
                'clicks' => (int) $k->clicks,
                'impressions' => (int) $k->impressions,
                'conversions' => (int) $k->conversions,
                'cost' => round((float) $k->cost, 2),
                'ctr' => $k->impressions > 0 ? round($k->clicks / $k->impressions * 100, 2) : null,
                'cpa' => $k->conversions > 0 ? round($k->cost / $k->conversions, 2) : null,
            ])
            ->all();

        $ctr = $metrics['impressions'] > 0 ? round($metrics['clicks'] / $metrics['impressions'] * 100, 2) : null;
        $cpc = $metrics['clicks'] > 0 ? round($metrics['spend'] / $metrics['clicks'], 2) : null;

        return [
            'metrics' => $metrics + ['ctr' => $ctr, 'avg_cpc' => $cpc],
            'byCampaign' => $byCampaign,
            'topKeywords' => $topKeywords,
            'recommendation' => $this->recommend($metrics, $ctr, $byCampaign, $topKeywords, $start, $end),
        ];
    }

    /**
     * @return array{verdict: string, budget: string, summary: string, actions: list<string>, source: string}
     */
    private function recommend(array $m, ?float $ctr, array $byCampaign, array $topKeywords, CarbonInterface $start, CarbonInterface $end): array
    {
        if ($m['spend'] <= 0) {
            return [
                'verdict' => 'No spend', 'budget' => 'n/a',
                'summary' => 'No Google Ads spend recorded for this period — import a Google Ads report to see analysis.',
                'actions' => [], 'source' => 'rule',
            ];
        }

        $key = 'ads_reco_'.$start->toDateString().'_'.$end->toDateString().'_'.md5(json_encode($m));

        return Cache::remember($key, 3600, function () use ($m, $ctr, $byCampaign, $topKeywords) {
            if ($this->ai->configured()) {
                $ai = $this->aiRecommend($m, $ctr, $byCampaign, $topKeywords);
                if ($ai) {
                    return $ai + ['source' => 'ai'];
                }
            }

            return $this->ruleRecommend($m, $ctr) + ['source' => 'rule'];
        });
    }

    private function aiRecommend(array $m, ?float $ctr, array $byCampaign, array $topKeywords): ?array
    {
        $campaigns = collect($byCampaign)->take(6)
            ->map(fn ($c) => "{$c['campaign']}: £{$c['spend']} spend, {$c['conversions']} conv, CPA "
                .($c['cpa'] ? '£'.$c['cpa'] : 'n/a').', CTR '.($c['ctr'] ?? 'n/a').'%')->implode('; ');
        $keywords = collect($topKeywords)->take(8)
            ->map(fn ($k) => "\"{$k['keyword']}\": £{$k['cost']}, {$k['conversions']} conv")->implode('; ');

        $facts = "Google Ads for a Sheffield executive chauffeur / airport-transfer firm.\n"
            ."Spend £{$m['spend']}, revenue £{$m['revenue']} (actual completed jobs), ROAS "
            .($m['roas'] ?? 'n/a').", {$m['conversions']} conversions, cost/conversion "
            .($m['cost_per_conversion'] ? '£'.$m['cost_per_conversion'] : 'n/a').", {$m['clicks']} clicks, CTR "
            .($ctr ?? 'n/a')."%.\nCampaigns: {$campaigns}.\nTop keywords by spend: {$keywords}.";

        $system = 'You are a paid-search analyst for a UK executive chauffeur firm. Reply ONLY with JSON: '
            .'{"verdict": one of "Increase budget"|"Hold"|"Reduce/optimise", '
            .'"budget": short phrase e.g. "+20%" or "hold" or "cut wasted spend", '
            .'"summary": 2 sentences citing the actual figures, '
            .'"actions": [up to 4 short specific actions — keywords to scale/pause, CPA targets, extensions]}. '
            .'Rule of thumb: ROAS above ~4 and low CPA → increase; ROAS 2–4 → hold/optimise; below 2 → reduce/fix.';

        $r = $this->ai->completeJson($facts, $system, ['max_tokens' => 600]);
        if (! $r || empty($r['summary'])) {
            return null;
        }

        return [
            'verdict' => (string) ($r['verdict'] ?? 'Hold'),
            'budget' => (string) ($r['budget'] ?? 'hold'),
            'summary' => (string) $r['summary'],
            'actions' => array_values(array_filter((array) ($r['actions'] ?? []))),
        ];
    }

    private function ruleRecommend(array $m, ?float $ctr): array
    {
        $roas = $m['roas'];
        [$verdict, $budget] = match (true) {
            $roas === null => ['Hold', 'hold'],
            $roas >= 4 => ['Increase budget', '+20–30%'],
            $roas >= 2 => ['Hold', 'hold & optimise'],
            default => ['Reduce/optimise', 'cut wasted spend'],
        };

        $actions = [];
        if ($roas !== null && $roas >= 4) {
            $actions[] = 'Return is strong (ROAS '.$roas.'×) — raise the daily budget on the best campaigns.';
        }
        if ($roas !== null && $roas < 2) {
            $actions[] = 'ROAS below 2× — pause keywords with spend but no conversions.';
        }
        if ($m['cost_per_conversion']) {
            $actions[] = 'Cost per booking is £'.$m['cost_per_conversion'].' — set target-CPA bidding around this.';
        }
        if ($ctr !== null && $ctr < 3) {
            $actions[] = 'CTR is '.$ctr.'% — tighten ad copy / add sitelink & call extensions.';
        }
        $actions[] = 'Add negative keywords for cheap/minicab searches to protect the premium positioning.';

        return [
            'verdict' => $verdict, 'budget' => $budget,
            'summary' => 'Spend £'.$m['spend'].' returned £'.$m['revenue'].' in completed jobs (ROAS '
                .($roas ?? 'n/a').'×) from '.$m['conversions'].' conversions.',
            'actions' => array_slice($actions, 0, 4),
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
