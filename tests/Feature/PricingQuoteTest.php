<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pricing\FreeRoamPricer;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_free_roam_matches_the_price_guide_examples(): void
    {
        $p = app(FreeRoamPricer::class);

        // Straight from the CET Price Guide "Journey Price Examples":
        $this->assertEquals(50.00, $p->price('executive', 3));    // under 10mi = minimum
        $this->assertEquals(54.00, $p->price('executive', 12));   // 50 + 2×2.00
        $this->assertEquals(102.00, $p->price('executive', 36));  // 50 + 26×2.00
        $this->assertEquals(264.60, $p->price('executive', 120)); // 50 + 180 + 20×1.73
        $this->assertEquals(437.60, $p->price('executive', 220)); // 50 + 180 + 120×1.73
        $this->assertEquals(127.98, $p->price('minibus-8', 36));  // 70 + 26×2.23
        $this->assertEquals(170.98, $p->price('v-class', 36));    // 100 + 26×2.73

        // Rolls Royce has no automatic rate.
        $this->assertNull($p->price('rolls-royce-ghost', 40));
    }

    public function test_airport_run_uses_the_fixed_price(): void
    {
        $quotes = app(QuoteService::class);
        $exec = VehicleType::where('slug', 'executive')->first();

        $man = $quotes->quote('81 Hallam Grange Road, Sheffield S10', 'Manchester Airport (MAN), T2', $exec);
        $this->assertTrue($man['fixed']);
        $this->assertEquals(100.0, $man['price']);
        $this->assertStringContainsString('MAN', $man['basis']);

        $lhr = $quotes->quote('Sheffield S1', 'Heathrow Airport Terminal 5', $exec);
        $this->assertEquals(290.0, $lhr['price']);
    }

    public function test_free_roam_quote_uses_distance(): void
    {
        // No maps key → DistanceService returns its estimate (10 miles) → minimum fare.
        config(['services.google_maps.key' => null]);
        $quotes = app(QuoteService::class);
        $exec = VehicleType::where('slug', 'executive')->first();

        $q = $quotes->quote('Sheffield S1', 'Rotherham S60', $exec);
        $this->assertFalse($q['fixed']);
        $this->assertEquals(50.0, $q['price']); // 10mi estimate = minimum fare
    }

    public function test_estimate_endpoint_returns_a_price(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->actingAs($admin)->getJson(route('pricing.estimate', [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport (MAN)', 'vehicle_type_id' => $exec->id,
        ]))->assertOk()->assertJsonFragment(['price' => 100.0, 'fixed' => true]);
    }
}
