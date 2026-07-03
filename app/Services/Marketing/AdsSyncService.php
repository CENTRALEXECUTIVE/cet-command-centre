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
     * Native Google Ads export column names → our fields. Case-insensitive; the
     * exact header text Google uses ("Day", "Cost", "Impr.", "Conv. value", …).
     *
     * @var array<string, string>
     */
    private const COLUMN_ALIASES = [
        'day' => 'date', 'date' => 'date',
        'cost' => 'spend', 'spend' => 'spend',
        'clicks' => 'clicks',
        'impr.' => 'impressions', 'impr' => 'impressions', 'impressions' => 'impressions',
        'conversions' => 'conversions', 'conv.' => 'conversions', 'conv' => 'conversions',
        'conv. value' => 'revenue', 'all conv. value' => 'revenue', 'conversion value' => 'revenue', 'revenue' => 'revenue',
        'campaign' => 'campaign', 'jobs' => 'jobs',
    ];

    /**
     * Import a Google Ads CSV export. Works with the raw download from Google Ads
     * (title/preamble rows, "Cost"/"Impr." headers, currency symbols and thousands
     * commas are all handled), or a plain CSV with a `date` column plus any of:
     * spend, revenue, conversions, jobs, clicks, impressions, campaign.
     *
     * @return int Rows imported.
     */
    public function importCsv(string $path): int
    {
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        // Skip Google's title/preamble rows: the header is the first row that maps
        // to a `date` column.
        $header = null;
        while (($row = array_shift($rows)) !== null) {
            $mapped = array_map(fn ($h) => self::COLUMN_ALIASES[strtolower(trim((string) $h))] ?? strtolower(trim((string) $h)), $row);
            if (in_array('date', $mapped, true)) {
                $header = $mapped;
                break;
            }
        }
        if ($header === null) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), null));
            $date = trim((string) ($data['date'] ?? ''));
            // Skip blanks and Google's "Total: …" summary rows.
            if ($date === '' || ! preg_match('/\d/', $date) || stripos($date, 'total') !== false) {
                continue;
            }

            try {
                $this->upsertDaily([
                    'date' => $date,
                    'campaign' => $data['campaign'] ?? null,
                    'spend' => $this->num($data['spend'] ?? null),
                    'revenue' => $this->num($data['revenue'] ?? null),
                    'conversions' => (int) $this->num($data['conversions'] ?? null),
                    'jobs' => (int) $this->num($data['jobs'] ?? null),
                    'clicks' => (int) $this->num($data['clicks'] ?? null),
                    'impressions' => (int) $this->num($data['impressions'] ?? null),
                ]);
                $count++;
            } catch (\Throwable) {
                continue; // unparseable date/row → skip
            }
        }

        return $count;
    }

    /** Parse a number from a Google Ads cell (strips £/$, thousands commas, spaces). */
    private function num(mixed $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }
}
