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

    public function test_monthly_cancellations_and_payment_split(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-15 12:00:00'));
        $exec = VehicleType::where('slug', 'executive')->first();
        // Two completed jobs earlier this month: one paid, one part-paid.
        Booking::factory()->forVehicleType($exec)->create([
            'status' => BookingStatus::Complete->value, 'final_price' => 100,
            'payment_status' => 'paid', 'pickup_at' => now()->subDays(5),
        ]);
        Booking::factory()->forVehicleType($exec)->create([
            'status' => BookingStatus::Complete->value, 'final_price' => 300,
            'payment_status' => 'balance_remaining', 'pickup_at' => now()->subDays(3),
        ]);
        // One cancelled job.
        Booking::factory()->forVehicleType($exec)->create([
            'status' => BookingStatus::Cancelled->value, 'final_price' => 80,
            'pickup_at' => now()->subDays(4),
        ]);
        // A FUTURE cash job, still pending → the only genuine "to collect".
        Booking::factory()->forVehicleType($exec)->create([
            'status' => BookingStatus::Pending->value, 'final_price' => 150,
            'payment_status' => 'pending', 'pickup_at' => now()->addDays(5),
        ]);

        $reports = app(ReportService::class);
        $start = now()->startOfMonth();
        $end = now()->addDays(10)->endOfDay();

        $monthly = $reports->monthlyRevenue($start, $end);
        $this->assertEquals(400.0, $monthly->firstWhere('month', now()->format('Y-m'))['revenue']);
        $this->assertEquals(2, $monthly->firstWhere('month', now()->format('Y-m'))['jobs']);

        $cancel = $reports->cancellations($start, $end);
        $this->assertEquals(1, $cancel['cancelled']);
        $this->assertEquals(3, $cancel['total']); // 2 ran + 1 cancelled
        $this->assertEquals(33.3, $cancel['rate_pct']);

        $split = $reports->paymentSplit($start, $end);
        // Both PAST jobs are collected (paid up front, or cash taken on the day):
        $this->assertEquals(400.0, $split['collected']);
        // Only the FUTURE unpaid cash job is still to collect:
        $this->assertEquals(150.0, $split['outstanding']);
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

    public function test_ads_revenue_counts_bookings_that_came_through_in_the_period(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 12:00:00');
        $exec = VehicleType::where('slug', 'executive')->first();

        // Came through IN July, pickup in AUGUST → still counts (income that came in).
        Booking::factory()->forVehicleType($exec)->create(['final_price' => 200, 'pickup_at' => '2026-08-10 09:00', 'created_at' => '2026-07-05 10:00']);
        // Pickup in July but BOOKED in June → not this period's booking.
        Booking::factory()->forVehicleType($exec)->create(['final_price' => 999, 'pickup_at' => '2026-07-20 09:00', 'created_at' => '2026-06-28 10:00']);
        // Cancelled booking created in July → no income.
        Booking::factory()->forVehicleType($exec)->create(['final_price' => 500, 'status' => BookingStatus::Cancelled->value, 'created_at' => '2026-07-06 10:00']);

        $data = app(AdsDashboardService::class)->forPeriod(
            \Illuminate\Support\Carbon::parse('2026-07-01')->startOfDay(),
            \Illuminate\Support\Carbon::parse('2026-07-31')->endOfDay(),
        );

        $this->assertEquals(200.0, $data['revenue']); // only the July-created, non-cancelled one
        $this->assertEquals(1, $data['jobs']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_admin_can_view_reports_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('reports.revenue'))->assertOk()->assertSee('Revenue Reports');
        $this->actingAs($admin)->get(route('reports.ads'))->assertOk()->assertSee('Google Ads');
        $this->actingAs($admin)->get(route('reports.profit'))->assertOk()->assertSee('Profit');
    }

    public function test_profit_nets_driver_cost_and_treats_cash_correctly(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Cost Carl']);

        $when = now()->startOfMonth(); // definitely this month and already run

        // Card job: £100 fare, £45 driver pay → business keeps £55.
        $card = $this->completedJob(100, $driver->id);
        $card->forceFill(['payment_method' => \App\Enums\PaymentMethod::Card->value, 'pickup_at' => $when,
            'meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();

        // Cash job: £90 fare, driver keeps the £90 cash → business keeps £0 of it.
        $cash = $this->completedJob(90, $driver->id);
        $cash->forceFill(['payment_method' => \App\Enums\PaymentMethod::Cash->value, 'pickup_at' => $when])->save();

        $data = app(ReportService::class)->profit(now()->startOfMonth(), now()->endOfMonth());

        $this->assertEqualsWithDelta(190.0, $data['revenue'], 0.01);        // 100 + 90 turnover
        $this->assertEqualsWithDelta(135.0, $data['driver_cost'], 0.01);    // 45 pay + 90 cash kept
        $this->assertEqualsWithDelta(55.0, $data['commission'], 0.01);      // only the card margin
        $this->assertEqualsWithDelta(55.0, $data['net_profit'], 0.01);      // no ad spend this month
        $this->assertEqualsWithDelta(90.0, $data['cash_to_drivers'], 0.01);

        // Ad spend eats into the net profit.
        \App\Models\AdMetric::create(['date' => now()->startOfMonth()->toDateString(), 'campaign' => 'Test', 'spend' => 20, 'revenue' => 0, 'conversions' => 0, 'jobs' => 0]);
        $data = app(ReportService::class)->profit(now()->startOfMonth(), now()->endOfMonth());
        $this->assertEqualsWithDelta(55.0, $data['commission'], 0.01);      // margin unchanged
        $this->assertEqualsWithDelta(20.0, $data['ad_spend'], 0.01);
        $this->assertEqualsWithDelta(35.0, $data['net_profit'], 0.01);      // 55 − 20
    }

    public function test_profit_page_accepts_a_custom_date_range(): void
    {
        $admin = User::factory()->admin()->create();

        // The page renders for an arbitrary from/to range and labels the period.
        $this->actingAs($admin)
            ->get(route('reports.profit', ['start' => '2026-06-01', 'end' => '2026-06-15']))
            ->assertOk()
            ->assertSee('Profit')
            ->assertSee('01 Jun 2026')
            ->assertSee('15 Jun 2026');
    }

    public function test_profit_range_only_counts_jobs_inside_the_range(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        // Booked inside the range, and outside it.
        Booking::factory()->forVehicleType($exec)->create(['final_price' => 300, 'pickup_at' => '2026-06-10 09:00', 'created_at' => '2026-06-10 09:00']);
        Booking::factory()->forVehicleType($exec)->create(['final_price' => 999, 'pickup_at' => '2026-07-10 09:00', 'created_at' => '2026-07-10 09:00']);

        $data = app(ReportService::class)->profit(
            \Illuminate\Support\Carbon::parse('2026-06-01')->startOfDay(),
            \Illuminate\Support\Carbon::parse('2026-06-30')->endOfDay(),
        );

        $this->assertEqualsWithDelta(300.0, $data['revenue'], 0.01); // only the June job
    }

    public function test_profit_trend_returns_a_row_per_recent_month(): void
    {
        $trend = app(ReportService::class)->profitTrend(12);
        $this->assertCount(12, $trend);
        $this->assertSame(now()->startOfMonth()->format('Y-m'), end($trend)['month']); // newest is this month
        $this->assertArrayHasKey('net_profit', $trend[0]);
    }

    public function test_profit_page_shows_the_trend(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('reports.profit'))
            ->assertOk()
            ->assertSee('Profit trend', false);
    }

    public function test_money_owed_totals_driver_pay_still_due(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Owed Olly']);
        $when = now()->startOfMonth()->addDay();

        // A card job with £45 pay, nothing handed over yet → £45 owed to the driver.
        $job = $this->completedJob(100, $driver->id);
        $job->forceFill([
            'payment_method' => \App\Enums\PaymentMethod::Card->value, 'pickup_at' => $when,
            'meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]],
        ])->save();

        $data = app(ReportService::class)->moneyOwed(now()->startOfMonth(), now()->endOfMonth());

        $this->assertEqualsWithDelta(45.0, $data['driver_total'], 0.01);
        $this->assertSame('Owed Olly', $data['driver_rows']->first()['name']);
    }

    public function test_money_owed_page_renders(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('reports.owed'))
            ->assertOk()
            ->assertSee('Money owed')
            ->assertSee('Owed to drivers');
    }

    public function test_non_admin_cannot_view_reports(): void
    {
        $client = User::factory()->corporateClient()->create();
        $this->actingAs($client)->get(route('reports.revenue'))->assertForbidden();
    }
}
