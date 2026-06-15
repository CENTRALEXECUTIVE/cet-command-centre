<?php

namespace Tests\Feature;

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use Database\Seeders\PricingZoneSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedPriceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, PricingZoneSeeder::class]);
    }

    public function test_zones_are_seeded_with_postcode_mapping(): void
    {
        $this->assertDatabaseHas('pricing_zones', ['name' => 'Sheffield S20']);
        // S20 postcode resolves to the Sheffield S20 zone.
        $zone = app(FixedPriceService::class)->zoneForPostcode('S20 1AB');
        $this->assertEquals('Sheffield S20', $zone?->name);
    }

    public function test_admin_can_add_a_fixed_price_route(): void
    {
        $admin = User::factory()->admin()->create();
        $zone = PricingZone::where('slug', 'doncaster')->first();
        $exec = VehicleType::where('slug', 'executive')->first();
        $vclass = VehicleType::where('slug', 'v-class')->first();

        $this->actingAs($admin)->post(route('pricing.store'), [
            'pricing_zone_id' => $zone->id,
            'destination' => 'Heathrow Airport',
            'deposit' => 20,
            'prices' => [$exec->id => 300, $vclass->id => 450],
        ])->assertRedirect();

        $this->assertDatabaseHas('fixed_prices', [
            'pricing_zone_id' => $zone->id, 'destination_slug' => 'heathrow-airport',
            'vehicle_type_id' => $exec->id, 'price' => 300,
        ]);
        $this->assertEquals(2, FixedPrice::where('pricing_zone_id', $zone->id)->count());
    }

    public function test_resaving_updates_prices_and_blank_clears(): void
    {
        $admin = User::factory()->admin()->create();
        $zone = PricingZone::where('slug', 'doncaster')->first();
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->actingAs($admin)->post(route('pricing.store'), [
            'pricing_zone_id' => $zone->id, 'destination' => 'Heathrow Airport',
            'prices' => [$exec->id => 300],
        ]);
        // Re-save with a new price.
        $this->actingAs($admin)->post(route('pricing.store'), [
            'pricing_zone_id' => $zone->id, 'destination' => 'Heathrow Airport',
            'prices' => [$exec->id => 320],
        ]);

        $this->assertEquals(1, FixedPrice::where('pricing_zone_id', $zone->id)->where('vehicle_type_id', $exec->id)->count());
        $this->assertEquals('320.00', FixedPrice::where('vehicle_type_id', $exec->id)->first()->price);

        // Blank clears it.
        $this->actingAs($admin)->post(route('pricing.store'), [
            'pricing_zone_id' => $zone->id, 'destination' => 'Heathrow Airport',
            'prices' => [$exec->id => ''],
        ]);
        $this->assertEquals(0, FixedPrice::where('pricing_zone_id', $zone->id)->count());
    }

    public function test_non_admin_cannot_manage_pricing(): void
    {
        $this->actingAs(User::factory()->corporateClient()->create())
            ->get(route('pricing.index'))->assertForbidden();
    }
}
