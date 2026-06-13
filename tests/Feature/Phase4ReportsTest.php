<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\AdMetric;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Reporting\AdsDashboardService;
use App\Services\Reporting\ReportService;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase4ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class]);
    }

    private function completedJob(float $price, ?int $driverId = null): Booking
    {
        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'status' => BookingStatus::Complete->value,
            'final_price' => $price,
            'driver_id' => $driverId,
            'pickup_at' => now()->subDays(2),
        ]);
    }

    public function test_revenue_summary_and_driver_breakdown(): void
    {
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $this->completedJob(100, $abdi->id);
        $this->completedJob(50, $abdi->id);

        $reports = app(ReportService::class);
        $summary = $reports->summary(now()->subWeek(), now());

        $this->assertEquals(150.0, $summary['revenue']);
        $this->assertEquals(2, $summary['jobs']);
        $this->assertEquals(75.0, $summary['average_fare']);

        $byDriver = $reports->earningsByDriver(now()->subWeek(), now());
        $this->assertEquals(150.0, (float) $byDriver->first()->revenue);
    }

    public function test_period_comparison_computes_change(): void
    {
        $this->completedJob(200);

        $comparison = app(ReportService::class)->comparison(now()->subDays(6)->startOfDay(), now()->endOfDay());

        $this->assertEquals(200.0, $comparison['current']['revenue']);
        $this->assertEquals(0.0, $comparison['previous']['revenue']);
    }

    public function test_ads_dashboard_triggers_budget_alerts(): void
    {
        // Revenue threshold is £14,000 — create enough completed revenue.
        Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->count(3)->create([
            'status' => BookingStatus::Complete->value,
            'final_price' => 5000,
            'pickup_at' => now()->subDay(),
        ]);
        AdMetric::factory()->create(['date' => now()->subDay()->toDateString(), 'spend' => 1000, 'conversions' => 45]);

        $data = app(AdsDashboardService::class)->forPeriod(now()->subWeek(), now());

        $this->assertEquals(15000.0, $data['revenue']);
        $this->assertEquals(15.0, $data['roas']); // 15000 / 1000
        $metrics = collect($data['alerts'])->pluck('metric');
        $this->assertTrue($metrics->contains('Revenue'));
        $this->assertTrue($metrics->contains('Conversions'));
    }

    public function test_admin_can_view_reports_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('reports.revenue'))->assertOk()->assertSee('Revenue Reports');
        $this->actingAs($admin)->get(route('reports.ads'))->assertOk()->assertSee('Google Ads');
    }

    public function test_non_admin_cannot_view_reports(): void
    {
        $client = User::factory()->corporateClient()->create();
        $this->actingAs($client)->get(route('reports.revenue'))->assertForbidden();
    }
}
