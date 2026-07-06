<?php

namespace App\Services\Reporting;

use App\Models\Booking;
use App\Services\Ai\AnthropicService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The business Review: one place that pulls together bookings, revenue, vehicle
 * mix, top customers, routes and ad spend for a period — then asks Claude
 * (claude-opus-4-8) for a plain-English review: what's working, what to improve,
 * and the next steps. The AI section degrades to a built-in summary when no API
 * key is configured.
 */
class ReviewService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AdsDashboardService $ads,
        private readonly AnthropicService $ai,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $start, CarbonInterface $end): array
    {
        $comparison = $this->reports->comparison($start, $end);
        $byVehicle = $this->reports->byVehicleType($start, $end);
        $topCustomers = $this->reports->topCustomers($start, $end);
        $topRoutes = $this->reports->topRoutes($start, $end, 5);
        $byDriver = $this->reports->earningsByDriver($start, $end);
        $monthly = $this->reports->monthlyRevenue($start, $end);
        $cancellations = $this->reports->cancellations($start, $end);
        $payments = $this->reports->paymentSplit($start, $end);
        $ads = $this->ads->forPeriod($start, $end);
        $adsAnalysis = $this->ads->analysis($start, $end);
        $dataHealth = $this->reports->dataHealth($start, $end);
        $reserved = $this->reports->reservedSummary($start, $end);

        // Pipeline counts (not just completed) for the period.
        $pipeline = Booking::whereBetween('pickup_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $data = [
            'start' => $start,
            'end' => $end,
            'comparison' => $comparison,
            'byVehicle' => $byVehicle,
            'topCustomers' => $topCustomers,
            'topRoutes' => $topRoutes,
            'byDriver' => $byDriver,
            'monthly' => $monthly,
            'cancellations' => $cancellations,
            'payments' => $payments,
            'ads' => $ads,
            'pipeline' => $pipeline,
            'dataHealth' => $dataHealth,
            'reserved' => $reserved,
            'adsAnalysis' => $adsAnalysis,
        ];

        $data['insights'] = $this->insights($data);

        return $data;
    }

    /**
     * Claude-generated review. Cached 1 hour per period so the page is instant
     * and we don't re-bill the API on every refresh.
     *
     * @param  array<string, mixed>  $data
     * @return array{source: string, summary: string, improve: list<string>, next_steps: list<string>}
     */
    private function insights(array $data): array
    {
        $key = 'review_insights_'.$data['start']->toDateString().'_'.$data['end']->toDateString();

        return Cache::remember($key, 3600, function () use ($data) {
            if (! $this->ai->configured()) {
                return $this->fallbackInsights($data);
            }

            $facts = $this->facts($data);
            $system = 'You are a business analyst for Central Executive Transfers, a Sheffield executive '
                .'chauffeur and airport-transfer firm. Review the period figures and respond ONLY with JSON: '
                .'{"summary": string (2-3 sentences, plain English, £ figures), '
                .'"improve": [up to 4 short specific actions], '
                .'"next_steps": [up to 4 short concrete next steps]}. Be practical and specific to a UK '
                .'private-hire/chauffeur business; reference the actual numbers.';

            $result = $this->ai->completeJson($facts, $system, ['max_tokens' => 700]);
            if (! $result || empty($result['summary'])) {
                return $this->fallbackInsights($data);
            }

            return [
                'source' => 'ai',
                'summary' => (string) $result['summary'],
                'improve' => array_values(array_filter((array) ($result['improve'] ?? []))),
                'next_steps' => array_values(array_filter((array) ($result['next_steps'] ?? []))),
            ];
        });
    }

    /** @param array<string, mixed> $data */
    private function facts(array $data): string
    {
        $c = $data['comparison'];
        $vehicles = $data['byVehicle']->map(fn ($v) => ($v->vehicleType->name ?? 'Other').": {$v->jobs} jobs, £".round($v->revenue))->implode('; ');
        $customers = $data['topCustomers']->take(5)->map(fn ($x) => ($x->customer->name ?? 'Unknown').' (£'.round($x->revenue).')')->implode(', ');
        $ads = $data['ads'];

        return "Period {$data['start']->toFormattedDateString()} – {$data['end']->toFormattedDateString()}.\n"
            ."Revenue £{$c['current']['revenue']} ({$c['revenue_change_pct']}% vs previous), "
            ."{$c['current']['jobs']} completed jobs, average fare £{$c['current']['average_fare']}.\n"
            ."By vehicle: {$vehicles}.\n"
            ."Top customers: {$customers}.\n"
            ."Ads: spent £{$ads['spend']}, revenue £{$ads['revenue']}, ROAS "
            .($ads['roas'] ?? 'n/a').", {$ads['conversions']} conversions, {$ads['clicks']} clicks.";
    }

    /** @param array<string, mixed> $data */
    private function fallbackInsights(array $data): array
    {
        $c = $data['comparison'];
        $ads = $data['ads'];
        $dir = ($c['revenue_change_pct'] ?? 0) >= 0 ? 'up' : 'down';

        $improve = [];
        if (($ads['roas'] ?? 0) !== null && ($ads['roas'] ?? 0) < 3 && $ads['spend'] > 0) {
            $improve[] = 'Ad ROAS is '.($ads['roas'] ?? 0).'× — review keywords/landing pages to lift it above 3×.';
        }
        if (($c['current']['average_fare'] ?? 0) > 0) {
            $improve[] = 'Average fare is £'.$c['current']['average_fare'].' — test upselling Executive/V Class on airport runs.';
        }
        $improve[] = 'Chase repeat business from top customers with a corporate-account offer.';

        return [
            'source' => 'rules',
            'summary' => "Revenue is £{$c['current']['revenue']} from {$c['current']['jobs']} completed jobs ({$dir} "
                .abs($c['revenue_change_pct'] ?? 0)."% vs the previous period). Average fare £{$c['current']['average_fare']}. "
                .'(Add an Anthropic API key for a full AI review.)',
            'improve' => $improve,
            'next_steps' => [
                'Confirm next week’s airport jobs are all driver-allocated.',
                'Review pending payments (👀/💰) and chase balances.',
                'Check vehicle compliance/MOT expiries due this month.',
            ],
        ];
    }
}
