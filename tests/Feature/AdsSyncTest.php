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

    public function test_imports_native_google_ads_export(): void
    {
        // Mirrors a raw Google Ads download: title/preamble rows, native column
        // names, currency symbols, thousands commas, and a Total summary row.
        $csv = "\"Daily report (Jun 1, 2026-Jun 2, 2026)\"\n"
            ."\n"
            ."Day,Campaign,Cost,Clicks,Impr.,Conversions,Conv. value\n"
            ."2026-06-01,Airport Transfers,\"£1,017.00\",320,\"12,500\",32,\"1,600.00\"\n"
            ."2026-06-02,Airport Transfers,£90.50,40,900,3,150.00\n"
            ."Total: ,,\"£1,107.50\",360,\"13,400\",35,\"1,750.00\"\n";
        $path = tempnam(sys_get_temp_dir(), 'gads').'.csv';
        file_put_contents($path, $csv);

        $imported = app(AdsSyncService::class)->importCsv($path);

        $this->assertEquals(2, $imported); // Total row skipped
        $day1 = AdMetric::whereDate('date', '2026-06-01')->first();
        $this->assertEquals(1017.00, (float) $day1->spend);   // £ and comma stripped
        $this->assertEquals(12500, $day1->impressions);       // "12,500" parsed
        $this->assertEquals(32, $day1->conversions);
        $this->assertEquals('1.57', $day1->roas);             // 1600 / 1017
        @unlink($path);
    }

    public function test_imports_period_total_report_without_a_day_column(): void
    {
        // A keyword-level report (no Day column) → aggregated to one period metric,
        // dated to the range end read from the title.
        $csv = "Untitled report\n"
            ."\"June 4, 2026 - July 3, 2026\"\n"
            ."Campaign,Search keyword,Clicks,Cost,Conversions,Conv. value\n"
            ."Airport,sheffield airport taxi,26,\"64.15\",5,\"1,150.00\"\n"
            ."Airport,manchester taxi,10,25.64,1,210.00\n"
            ."Total: Search keywords,,36,89.79,6,1360.00\n";
        $path = tempnam(sys_get_temp_dir(), 'kw').'.csv';
        file_put_contents($path, $csv);

        $imported = app(AdsSyncService::class)->importCsv($path);

        $this->assertEquals(1, $imported); // one aggregated row
        $m = AdMetric::first();
        $this->assertEquals('2026-07-03', $m->date->toDateString()); // range end
        $this->assertEquals(89.79, (float) $m->spend);   // 64.15 + 25.64, Total row skipped
        $this->assertEquals(1360.00, (float) $m->revenue);
        $this->assertEquals(6, $m->conversions);
        $this->assertEquals(36, $m->clicks);
        $this->assertEquals('15.15', $m->roas);          // 1360 / 89.79
        @unlink($path);
    }

    public function test_keyword_report_import(): void
    {
        $csv = "Keyword,Match type,Campaign,Clicks,Impr.,Conversions,Cost,Status\n"
            ."\"airport transfers sheffield\",Phrase,Airport,120,\"3,400\",8,\"£240.50\",Enabled\n"
            ."\"executive taxi\",Exact,Exec,40,900,2,\"£88.00\",Enabled\n"
            ."Total: keywords,,,160,4300,10,328.50,\n";
        $path = tempnam(sys_get_temp_dir(), 'kw').'.csv';
        file_put_contents($path, $csv);

        $imported = app(AdsSyncService::class)->importKeywordsCsv($path);

        $this->assertSame(2, $imported); // "Total:" row skipped
        $kw = \App\Models\Keyword::where('keyword', 'airport transfers sheffield')->first();
        $this->assertNotNull($kw);
        $this->assertSame(120, $kw->clicks);
        $this->assertSame(3400, $kw->impressions);
        $this->assertEquals(240.50, (float) $kw->cost);

        // Re-import refreshes rather than duplicates.
        app(AdsSyncService::class)->importKeywordsCsv($path);
        $this->assertSame(2, \App\Models\Keyword::count());

        @unlink($path);
    }

    public function test_admin_can_upload_a_keyword_report_from_the_page(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        $csv = "Keyword,Match type,Clicks,Impr.,Conversions,Cost\nsheffield chauffeur,Phrase,10,200,1,£30\n";
        $file = \Illuminate\Http\Testing\File::createWithContent('keywords.csv', $csv);

        $this->actingAs($admin)->post(route('marketing.keywords.import'), ['file' => $file])->assertRedirect();
        $this->assertSame(1, \App\Models\Keyword::where('keyword', 'sheffield chauffeur')->count());
    }
}
