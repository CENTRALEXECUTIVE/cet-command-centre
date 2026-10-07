<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pricing\DistanceService;
use App\Services\Pricing\FreeRoamPricer;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Out-of-area "dead mileage": a far pickup is charged for the empty run out to
 * it (whole base→pickup distance × the per-mile rate), on top of the journey
 * fare — but local pickups inside the free radius pay nothing extra.
 */
class DeadMileagePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_a_pickup_inside_the_free_radius_pays_no_dead_mileage(): void
    {
        $p = app(FreeRoamPricer::class);

        // Default free radius is 15 miles.
        $this->assertSame(0.0, $p->deadMileageCharge(10.0));
        $this->assertSame(0.0, $p->deadMileageCharge(15.0));
        $this->assertSame(0.0, $p->deadMileageCharge(null));
    }

    public function test_a_far_pickup_is_charged_for_the_whole_empty_run(): void
    {
        $p = app(FreeRoamPricer::class);

        // 40 miles out at the default £1/mile = £40, rounded to a clean £5.
        $this->assertSame(40.0, $p->deadMileageCharge(40.0));
        // 23 miles out → £23 → rounds to £25.
        $this->assertSame(25.0, $p->deadMileageCharge(23.0));
    }

    public function test_the_office_can_change_the_rate_and_radius(): void
    {
        Setting::set('deadmile_radius', '20', 'string', 'pricing');
        Setting::set('deadmile_rate', '1.50', 'string', 'pricing');
        $p = app(FreeRoamPricer::class);

        // Now 18 miles is inside the (20mi) free radius.
        $this->assertSame(0.0, $p->deadMileageCharge(18.0));
        // 40 miles × £1.50 = £60.
        $this->assertSame(60.0, $p->deadMileageCharge(40.0));
    }

    public function test_a_free_roam_quote_adds_dead_mileage_for_a_far_pickup(): void
    {
        // Stub distances: the booked journey is 20 miles, and base→pickup is 40.
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')
            ->andReturnUsing(function (string $from, string $to) {
                // The base→pickup call starts from the configured base address.
                $isBaseLeg = str_contains(strtolower($from), 'sheffield');

                return ['miles' => $isBaseLeg ? 40.0 : 20.0, 'minutes' => 60, 'source' => 'google'];
            });
        $this->app->instance(DistanceService::class, $distance);

        $exec = VehicleType::where('slug', 'executive')->first();
        $quote = app(QuoteService::class)->quote('Goole DN14', 'Leeds LS1', $exec);

        // Journey (20mi exec, ex-VAT = 50 + 10×2 = £70) + £40 dead mileage = £110.
        $this->assertSame(40.0, $quote['dead_mileage']);
        $this->assertSame(40.0, $quote['dead_mileage_miles']);
        $this->assertSame(110.0, $quote['price']);
        $this->assertSame(132.0, $quote['price_with_vat']); // £110 + 20% VAT
        $this->assertStringContainsString('out-of-area', $quote['basis']);
    }

    public function test_a_local_free_roam_quote_has_no_dead_mileage(): void
    {
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')
            ->andReturn(['miles' => 8.0, 'minutes' => 20, 'source' => 'google']);
        $this->app->instance(DistanceService::class, $distance);

        $exec = VehicleType::where('slug', 'executive')->first();
        $quote = app(QuoteService::class)->quote('Sheffield S1', 'Rotherham S60', $exec);

        $this->assertSame(0.0, $quote['dead_mileage']);
        $this->assertStringNotContainsString('out-of-area', $quote['basis']);
    }

    public function test_the_admin_can_save_the_dead_mileage_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('free-roam.update'), [
            'rates' => [
                'executive' => ['flat' => 50, 'tier1' => 2.00, 'tier2' => 1.73],
                'minibus-8' => ['flat' => 70, 'tier1' => 2.23, 'tier2' => 2.15],
                'minibus-8-xl' => ['flat' => 90, 'tier1' => 2.23, 'tier2' => 2.15],
                'v-class' => ['flat' => 100, 'tier1' => 2.73, 'tier2' => 2.65],
            ],
            'vat_uplift' => 10,
            'estate_uplift' => 10,
            'deadmile_rate' => 2,
            'deadmile_radius' => 25,
        ])->assertRedirect();

        $p = app(FreeRoamPricer::class);
        $this->assertSame(2.0, $p->deadMileageRate());
        $this->assertSame(25.0, $p->deadMileageFreeRadius());
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
