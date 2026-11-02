<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pricing\QuoteService;
use App\Support\TimeSurcharges;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Office-defined time-based pricing (night + holiday uplifts), applied to the fare for
 * the pickup time — zero by default, so existing fares are unchanged until configured.
 */
class TimeSurchargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function quote(Carbon $at): ?float
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        return app(QuoteService::class)->quote('Sheffield S1 2HH', 'Manchester Airport', $exec, $at)['price'];
    }

    public function test_no_surcharge_by_default(): void
    {
        $this->assertEqualsWithDelta(105.0, $this->quote(Carbon::parse('2026-10-05 03:00')), 0.01);
    }

    public function test_a_night_surcharge_is_added_in_the_window_only(): void
    {
        TimeSurcharges::save(
            ['enabled' => true, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 20],
            [],
        );

        // 03:00 is in the window → £105 + 20% = £126.
        $this->assertEqualsWithDelta(126.0, $this->quote(Carbon::parse('2026-10-05 03:00')), 0.01);
        // 14:00 is outside → unchanged £105.
        $this->assertEqualsWithDelta(105.0, $this->quote(Carbon::parse('2026-10-05 14:00')), 0.01);
    }

    public function test_a_dated_uplift_applies_on_that_day(): void
    {
        // A non-festive date (the festive table wins on its own days — see below).
        TimeSurcharges::save(
            ['enabled' => false, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 0],
            [['date' => '2026-11-20', 'label' => 'Concert night', 'type' => 'fixed', 'value' => 40]],
        );

        $this->assertEqualsWithDelta(145.0, $this->quote(Carbon::parse('2026-11-20 11:00')), 0.01); // +£40
        $this->assertEqualsWithDelta(105.0, $this->quote(Carbon::parse('2026-11-19 11:00')), 0.01); // day before, none
    }

    public function test_the_festive_table_wins_over_an_office_date_uplift_on_the_same_day(): void
    {
        // Directors: never charge both festive-style surcharges for one moment.
        TimeSurcharges::save(
            ['enabled' => false, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 0],
            [['date' => '2026-12-25', 'label' => 'Christmas Day', 'type' => 'fixed', 'value' => 40]],
        );

        // Christmas Day +50% on £105 = £157.50 — NOT also +£40.
        $this->assertEqualsWithDelta(157.5, $this->quote(Carbon::parse('2026-12-25 11:00')), 0.01);
    }

    public function test_night_rate_and_festive_are_both_on_the_standard_fare_never_compounded(): void
    {
        TimeSurcharges::save(['enabled' => true, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 20], []);

        // 1 Jan 03:00: festive +80% (£84) + night +20% (£21), both on £105 → £210,
        // not £105 × 1.8 × 1.2 = £226.80.
        $this->assertEqualsWithDelta(210.0, $this->quote(Carbon::parse('2027-01-01 03:00')), 0.01);
    }

    public function test_admin_can_save_night_and_dated_rules(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('time-surcharges.update'), [
            'night_enabled' => '1', 'night_from' => '23:00', 'night_to' => '05:00',
            'night_type' => 'percent', 'night_value' => 15,
            'dates' => "2026-12-25 | Christmas Day | percent | 50\nrubbish line\n2026-12-31 | NYE | fixed | 25",
        ])->assertRedirect();

        $rules = TimeSurcharges::rules();
        $this->assertTrue($rules['night']['enabled']);
        $this->assertSame('23:00', $rules['night']['from']);
        $this->assertCount(2, $rules['dates']); // malformed line skipped
        $this->assertSame('Christmas Day', $rules['dates'][0]['label']);
    }

    public function test_a_driver_cannot_access_time_pricing(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('time-surcharges.index'))->assertForbidden();
    }
}
