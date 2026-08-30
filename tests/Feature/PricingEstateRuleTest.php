<?php

namespace Tests\Feature;

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The estate rule: an Estate fare is always the Executive fare + £10, derived at
 * quote time so a wrong stored figure (ETO had some at +£5) can never be quoted.
 */
class PricingEstateRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_db_lookup_derives_estate_as_executive_plus_ten(): void
    {
        $service = app(FixedPriceService::class);
        $zone = PricingZone::create(['name' => 'Sheffield', 'slug' => 'sheffield', 'is_active' => true]);
        $exec = VehicleType::where('slug', 'executive')->first();
        $estate = VehicleType::where('slug', 'estate')->first();

        // Executive fare £100; a WRONG stored estate at £105 (+£5).
        $service->upsert($zone, 'Manchester Airport', $exec, 100);
        $service->upsert($zone, 'Manchester Airport', $estate, 105);

        // Estate must come back as £110 (executive + £10), not the stored £105.
        $row = $service->lookup($zone, 'Manchester Airport', $estate);
        $this->assertNotNull($row);
        $this->assertSame(110.0, (float) $row->price);
    }

    public function test_the_widget_engine_derives_estate_as_executive_plus_ten(): void
    {
        $estate = VehicleType::where('slug', 'estate')->first();

        // Sheffield → Manchester Airport is a fixed £100 for Executive in the
        // built-in matrix, so Estate must quote £110.
        $result = app(QuoteService::class)->quote('Sheffield S1 2HH', 'Manchester Airport', $estate);
        $this->assertSame(110.0, (float) $result['price']);
        $this->assertTrue($result['fixed']);
    }

    public function test_the_normalise_command_fixes_stored_estate_rows(): void
    {
        $service = app(FixedPriceService::class);
        $zone = PricingZone::create(['name' => 'Barnsley', 'slug' => 'barnsley', 'is_active' => true]);
        $exec = VehicleType::where('slug', 'executive')->first();
        $estate = VehicleType::where('slug', 'estate')->first();
        $service->upsert($zone, 'Leeds Bradford Airport', $exec, 100);
        $service->upsert($zone, 'Leeds Bradford Airport', $estate, 105); // wrong +£5

        $this->artisan('cet:normalise-estate-prices')->assertSuccessful();

        $stored = FixedPrice::where('vehicle_type_id', $estate->id)
            ->where('pricing_zone_id', $zone->id)->first();
        $this->assertSame('110.00', (string) $stored->price);
    }
}
