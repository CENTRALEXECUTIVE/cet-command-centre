<?php

namespace Tests\Feature;

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Database\Seeders\WidgetFixedPriceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office can edit fixed prices and the widget reflects them. The DB matrix is
 * authoritative; the built-in matrix is the seed/fallback, so prices are unchanged
 * until edited.
 */
class EditableFixedPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function quote(string $pickup, string $dropoff, string $vehicleSlug): ?float
    {
        $vt = VehicleType::where('slug', $vehicleSlug)->firstOrFail();

        return app(QuoteService::class)->quote($pickup, $dropoff, $vt)['price'];
    }

    public function test_with_no_db_rows_the_builtin_matrix_is_used(): void
    {
        // Nothing seeded → Sheffield → Manchester executive is the built-in £105.
        $this->assertEqualsWithDelta(105.0, $this->quote('Sheffield S1 2HH', 'Manchester Airport', 'executive'), 0.01);
    }

    public function test_the_seeder_mirrors_the_builtin_matrix_exactly(): void
    {
        $this->seed(WidgetFixedPriceSeeder::class);

        // Same numbers as the built-in matrix — nothing changes for customers.
        $this->assertEqualsWithDelta(105.0, $this->quote('Sheffield S1 2HH', 'Manchester Airport', 'executive'), 0.01);
        $this->assertEqualsWithDelta(140.0, $this->quote('Sheffield S1 2HH', 'Manchester Airport', 'minibus-8'), 0.01);
        // Estate is derived (executive + £10).
        $this->assertEqualsWithDelta(115.0, $this->quote('Sheffield S1 2HH', 'Manchester Airport', 'estate'), 0.01);
    }

    public function test_an_edited_price_changes_the_widget_quote(): void
    {
        $this->seed(WidgetFixedPriceSeeder::class);

        // Office raises the Sheffield → Manchester executive fare to £120.
        $zone = PricingZone::where('slug', 'sheffield')->firstOrFail();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        app(FixedPriceService::class)->upsert($zone, 'Manchester Airport', $exec, 120.0);

        $this->assertEqualsWithDelta(120.0, $this->quote('Sheffield S1 2HH', 'Manchester Airport', 'executive'), 0.01);
        // Estate follows automatically (120 + 10).
        $this->assertEqualsWithDelta(130.0, $this->quote('Sheffield S1 2HH', 'Manchester Airport', 'estate'), 0.01);
    }

    public function test_a_price_can_be_set_for_a_deep_zone(): void
    {
        $this->seed(WidgetFixedPriceSeeder::class);
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        // Office carves out a dearer fare for a specific "deep" area (S71).
        $deep = PricingZone::create(['name' => 'Barnsley Deep', 'slug' => 'barnsley-deep', 'postcode_prefixes' => ['S71'], 'is_active' => true]);
        app(FixedPriceService::class)->upsert($deep, 'Manchester Airport', $exec, 125.0);

        // An S71 pickup now gets the deep fare through the widget; a normal Barnsley
        // (S70) pickup keeps the standard £105.
        $this->assertEqualsWithDelta(125.0, $this->quote('Barnsley S71 1AA', 'Manchester Airport', 'executive'), 0.01);
        $this->assertEqualsWithDelta(105.0, $this->quote('Barnsley S70 1AA', 'Manchester Airport', 'executive'), 0.01);
    }
}
