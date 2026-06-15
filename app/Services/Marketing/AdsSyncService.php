<?php

namespace App\Services\Marketing;

use App\Models\AdMetric;
use Illuminate\Support\Carbon;

/**
 * Syncs daily Google Ads metrics into ad_metrics (feeding the ads dashboard +
 * budget alerts). Live pull requires the Google Ads API credentials; a CSV
 * export from Google Ads can be imported in the meantime via importCsv().
 */
class AdsSyncService
{
    public function configured(): bool
    {
        return filled(config('services.google_ads.developer_token'))
            && filled(config('services.google_ads.customer_id'));
    }

    /**
     * Upsert one day's metrics. Computes ROAS from revenue/spend.
     *
     * @param  array{date:string, spend?:float, revenue?:float, conversions?:int, jobs?:int, clicks?:int, impressions?:int, campaign?:string}  $row
     */
    public function upsertDaily(array $row): AdMetric
    {
        $spend = (float) ($row['spend'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);

        return AdMetric::updateOrCreate(
            ['date' => Carbon::parse($row['date'])->toDateString()],
            [
                'campaign' => $row['campaign'] ?? null,
                'spend' => $spend,
                'revenue' => $revenue,
                'conversions' => (int) ($row['conversions'] ?? 0),
                'jobs' => (int) ($row['jobs'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            ],
        );
    }

    /**
     * Import a Google Ads CSV export. Header (case-insensitive) must include
     * `date` plus any of: spend, revenue, conversions, jobs, clicks, impressions, campaign.
     *
     * @return int Rows imported.
     */
    public function importCsv(string $path): int
    {
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows));
        $count = 0;

        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), null));
            if (blank($data['date'] ?? null)) {
                continue;
            }
            $this->upsertDaily($data);
            $count++;
        }

        return $count;
    }
}
