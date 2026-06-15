<?php

namespace Tests\Feature;

use App\Models\AdMetric;
use App\Services\Marketing\AdsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_daily_computes_roas(): void
    {
        $metric = app(AdsSyncService::class)->upsertDaily([
            'date' => '2026-06-01', 'spend' => 100, 'revenue' => 450, 'conversions' => 12, 'jobs' => 9,
        ]);

        $this->assertEquals('4.50', $metric->roas);
        $this->assertEquals(12, $metric->conversions);
    }

    public function test_upsert_is_idempotent_per_day(): void
    {
        $svc = app(AdsSyncService::class);
        $svc->upsertDaily(['date' => '2026-06-01', 'spend' => 100, 'revenue' => 400]);
        $svc->upsertDaily(['date' => '2026-06-01', 'spend' => 120, 'revenue' => 600]);

        $this->assertEquals(1, AdMetric::whereDate('date', '2026-06-01')->count());
        $this->assertEquals('5.00', AdMetric::first()->roas); // 600/120
    }

    public function test_csv_import(): void
    {
        $csv = "date,spend,revenue,conversions,jobs,clicks,impressions\n"
            ."2026-06-01,100,500,15,10,200,4000\n2026-06-02,80,300,8,6,150,3000\n";
        $path = tempnam(sys_get_temp_dir(), 'ads').'.csv';
        file_put_contents($path, $csv);

        $this->artisan('cet:sync-ads', ['--csv' => $path])->assertSuccessful();

        $this->assertEquals(2, AdMetric::count());
        $this->assertEquals('5.00', AdMetric::whereDate('date', '2026-06-01')->first()->roas);
        @unlink($path);
    }
}
