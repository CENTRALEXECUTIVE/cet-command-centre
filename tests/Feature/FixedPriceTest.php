<?php

namespace Tests\Feature;

use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\AiPricingEngine;
use Database\Seeders\FixedPriceSeeder;
use Database\Seeders\PricingSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, PricingSeeder::class, FixedPriceSeeder::class]);
    }

    private function executive(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->first();
    }

    public function test_fixed_price_is_authoritative_when_zone_and_destination_match(): void
    {
        $zone = PricingZone::where('slug', 'barnsley-deep')->first();

        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $this->executive()->id,
            'pricing_zone_id' => $zone->id,
            'pickup_address' => 'Barnsley',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => '2026-06-15 14:00',
        ]);

        // Barnsley Deep → Manchester Airport, Executive = £110 (fixed).
        $this->assertEquals(110.0, (float) $quote->price);
        $this->assertFalse($quote->ai_generated);
        $this->assertEquals('fixed_price', $quote->breakdown['source']);
        $this->assertEquals('Barnsley Deep', $quote->breakdown['zone']);
    }

    public function test_fixed_price_resolved_from_pickup_postcode(): void
    {
        // Sheffield S20 zone is mapped to the S20 outward code.
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $this->executive()->id,
            'pickup_postcode' => 'S20 1AB',
            'pickup_address' => '1 Test Road, Sheffield',
            'destination_address' => 'Liverpool Airport',
            'pickup_at' => '2026-06-15 14:00',
        ]);

        // Sheffield S20 → Liverpool Airport, Executive = £155.
        $this->assertEquals(155.0, (float) $quote->price);
        $this->assertEquals('fixed_price', $quote->breakdown['source']);
    }

    public function test_falls_back_to_distance_pricing_when_no_fixed_match(): void
    {
        $zone = PricingZone::where('slug', 'barnsley-deep')->first();

        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $this->executive()->id,
            'pricing_zone_id' => $zone->id,
            'pickup_address' => 'Barnsley',
            'destination_address' => 'Some Random Street', // not in the matrix
            'pickup_at' => '2026-06-15 14:00',
            'distance_miles' => 10,
            'duration_minutes' => 20,
        ]);

        // No fixed price → rule-based £36.
        $this->assertEquals(36.0, (float) $quote->price);
        $this->assertEquals(36.0, $quote->breakdown['rule_price'] ?? null);
    }

    public function test_csv_importer_loads_the_matrix(): void
    {
        $csv = "zone,destination,deposit,Estate,Executive,8 Seater,8 Seater XL,Executive 8 Seater,Luxury\n"
            ."Chesterfield,Heathrow Airport,0,260,255,310,335,420,0\n";
        $path = tempnam(sys_get_temp_dir(), 'fp').'.csv';
        file_put_contents($path, $csv);

        $this->artisan('cet:import-fixed-prices', ['path' => $path])->assertSuccessful();

        $zone = PricingZone::where('slug', 'chesterfield')->first();
        $this->assertNotNull($zone);
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $this->executive()->id,
            'pricing_zone_id' => $zone->id,
            'pickup_address' => 'Chesterfield',
            'destination_address' => 'Heathrow Airport',
            'pickup_at' => '2026-06-15 14:00',
        ]);

        $this->assertEquals(255.0, (float) $quote->price); // Executive column
        @unlink($path);
    }
}
