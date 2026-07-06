<?php

namespace Tests\Feature;

use App\Models\AdMetric;
use App\Services\Reporting\AdsDashboardService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdsAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_analysis_produces_metrics_and_a_recommendation(): void
    {
        AdMetric::create([
            'date' => now()->subDays(3)->toDateString(), 'campaign' => 'Airport Transfers',
            'spend' => 100, 'revenue' => 0, 'conversions' => 10, 'clicks' => 200, 'impressions' => 4000,
        ]);

        $a = app(AdsDashboardService::class)->analysis(now()->subDays(30), now());

        $this->assertEquals(100.0, $a['metrics']['spend']);
        $this->assertEquals(5.0, $a['metrics']['ctr']); // 200/4000
        $this->assertNotEmpty($a['byCampaign']);
        $this->assertArrayHasKey('verdict', $a['recommendation']);
        $this->assertNotEmpty($a['recommendation']['summary']);
    }

    public function test_no_spend_gives_a_clean_message(): void
    {
        $a = app(AdsDashboardService::class)->analysis(now()->subDays(30), now());
        $this->assertEquals('No spend', $a['recommendation']['verdict']);
    }
}
