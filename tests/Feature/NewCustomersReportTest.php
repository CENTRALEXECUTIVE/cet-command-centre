<?php

namespace Tests\Feature;

use App\Models\AdMetric;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Reporting\ReportService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NewCustomersReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(Customer $c, string $date, string $source): void
    {
        Booking::factory()->create([
            'customer_id' => $c->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->firstOrFail()->id,
            'pickup_at' => Carbon::parse($date),
            'source' => $source,
        ]);
    }

    public function test_new_customers_are_counted_by_their_first_booking_month_and_channel(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $cc = Customer::factory()->create();

        // A: first booking Jan (web), plus a Feb repeat → A is new in Jan only.
        $this->booking($a, '2026-01-10 09:00', 'web');
        $this->booking($a, '2026-02-10 09:00', 'web');
        // B: first booking Jan (phone) → new in Jan, "other".
        $this->booking($b, '2026-01-20 09:00', 'phone');
        // C: first booking Feb (web) → new in Feb, web.
        $this->booking($cc, '2026-02-05 09:00', 'web');

        // Ad spend: £100 in Jan, £60 in Feb.
        AdMetric::create(['date' => '2026-01-15', 'campaign' => 'x', 'spend' => 100, 'conversions' => 2]);
        AdMetric::create(['date' => '2026-02-15', 'campaign' => 'x', 'spend' => 60, 'conversions' => 1]);

        $rows = app(ReportService::class)->newCustomersByMonth(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-02-28')
        );
        $byMonth = collect($rows)->keyBy('month');

        $jan = $byMonth['2026-01'];
        $this->assertSame(2, $jan['new_customers']);   // A + B
        $this->assertSame(1, $jan['web']);             // A
        $this->assertSame(1, $jan['other']);           // B
        $this->assertSame(100.0, $jan['ad_spend']);
        $this->assertSame(100.0, $jan['cost_per_web']); // £100 / 1 web

        $feb = $byMonth['2026-02'];
        $this->assertSame(1, $feb['new_customers']);   // C only (A was already a customer)
        $this->assertSame(1, $feb['web']);
        $this->assertSame(60.0, $feb['cost_per_web']);
    }

    public function test_a_month_with_spend_but_no_new_web_customers_has_null_cost_per_web(): void
    {
        AdMetric::create(['date' => '2026-03-15', 'campaign' => 'x', 'spend' => 40, 'conversions' => 0]);

        $rows = app(ReportService::class)->newCustomersByMonth(
            Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')
        );

        $this->assertSame(40.0, $rows[0]['ad_spend']);
        $this->assertSame(0, $rows[0]['web']);
        $this->assertNull($rows[0]['cost_per_web']); // spent but won none — flagged in the view
    }

    public function test_the_report_page_loads_for_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.customers'))
            ->assertOk()
            ->assertSee('New customers by month');
    }
}
