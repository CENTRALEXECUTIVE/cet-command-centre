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

        // Price Guide rates + £10 VAT uplift, rounded to the nearest £5:
        $this->assertEquals(60.00, $p->price('executive', 3));    // 50 min +10 = 60
        $this->assertEquals(65.00, $p->price('executive', 12));   // 54 +10 = 64 → 65
        $this->assertEquals(110.00, $p->price('executive', 36));  // 102 +10 = 112 → 110
        $this->assertEquals(275.00, $p->price('executive', 120)); // 264.60 +10 = 274.60 → 275
        $this->assertEquals(450.00, $p->price('executive', 220)); // 437.60 +10 = 447.60 → 450
        $this->assertEquals(140.00, $p->price('minibus-8', 36));  // 127.98 +10 = 137.98 → 140
        $this->assertEquals(180.00, $p->price('v-class', 36));    // 170.98 +10 = 180.98 → 180
        $this->assertEquals(120.00, $p->price('estate', 36));     // executive 110 + £10

        // Rolls Royce has no automatic rate.
        $this->assertNull($p->price('rolls-royce-ghost', 40));
    }

    public function test_airport_run_uses_the_fixed_price_both_ways(): void
    {
        $quotes = app(QuoteService::class);
        $exec = VehicleType::where('slug', 'executive')->first();

        // Drop-off is the airport.
        $man = $quotes->quote('81 Hallam Grange Road, Sheffield S10 4BL', 'Manchester Airport (MAN), T2', $exec);
        // NEW post-VAT rates: every fixed route rose by £10.
        $this->assertTrue($man['fixed']);
        $this->assertEquals(110.0, $man['price']);

        // Pickup is the airport (both ways = same price, zone from the other end).
        $manReturn = $quotes->quote('Manchester Airport (MAN)', '81 Hallam Grange Road, Sheffield S10 4BL', $exec);
        $this->assertEquals(110.0, $manReturn['price']);

        $this->assertEquals(300.0, $quotes->quote('Sheffield S1 2HH', 'Heathrow Terminal 5', $exec)['price']);
        $this->assertEquals(310.0, $quotes->quote('Sheffield S1', 'Central London', $exec)['price']);
        $this->assertEquals(410.0, $quotes->quote('Sheffield S1', 'Port of Southampton', $exec)['price']);
    }

    public function test_zone_specific_prices(): void
    {
        $quotes = app(QuoteService::class);
        $exec = VehicleType::where('slug', 'executive')->first();

        // East Midlands: £100 from S20/Chesterfield, £110 from general Sheffield.
        $this->assertEquals(100.0, $quotes->quote('Sheffield S20 1AB', 'East Midlands Airport', $exec)['price']);
        $this->assertEquals(100.0, $quotes->quote('Chesterfield S40 1AA', 'East Midlands Airport', $exec)['price']);
        $this->assertEquals(110.0, $quotes->quote('Sheffield S10 4BL', 'East Midlands Airport', $exec)['price']);

        // Birmingham: £150 from S20/Chesterfield, £160 from Sheffield/Rotherham.
        $this->assertEquals(150.0, $quotes->quote('Sheffield S20 1AB', 'Birmingham Airport', $exec)['price']);
        $this->assertEquals(160.0, $quotes->quote('Rotherham S60 1AA', 'Birmingham Airport', $exec)['price']);

        // V Class (Executive 8 Seater) fixed column, Heathrow.
        $vclass = VehicleType::where('slug', 'v-class')->first();
        $this->assertEquals(460.0, $quotes->quote('Sheffield S1', 'Heathrow', $vclass)['price']);
    }

    public function test_free_roam_quote_uses_distance(): void
    {
        // No maps key → DistanceService returns its estimate (10 miles) → minimum fare.
        config(['services.google_maps.key' => null]);
        $quotes = app(QuoteService::class);
        $exec = VehicleType::where('slug', 'executive')->first();

        $q = $quotes->quote('Sheffield S1', 'Rotherham S60', $exec);
        $this->assertFalse($q['fixed']);
        $this->assertEquals(60.0, $q['price']); // 10mi estimate = min fare £50 + £10
    }

    public function test_estimate_endpoint_returns_a_price(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();

        $this->actingAs($admin)->getJson(route('pricing.estimate', [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport (MAN)', 'vehicle_type_id' => $exec->id,
        ]))->assertOk()->assertJsonFragment(['price' => 110.0, 'fixed' => true]);
    }
}
