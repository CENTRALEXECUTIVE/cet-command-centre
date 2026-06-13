<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use App\Services\Pricing\AiPricingEngine;
use App\Services\Pricing\PricingRuleEngine;
use Database\Seeders\PricingSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AiPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, PricingSeeder::class]);
    }

    private function executive(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->first();
    }

    public function test_rule_engine_computes_fare_from_distance_and_time(): void
    {
        // Executive: base 8 + (2.20*10) + (0.30*20) = 8 + 22 + 6 = 36, daytime.
        $result = app(PricingRuleEngine::class)->quote(
            $this->executive(), miles: 10, minutes: 20, pickupAt: Carbon::parse('2026-06-15 14:00'), isAirport: false
        );

        $this->assertEquals(36.0, $result['total']);
        $this->assertEquals(22.0, $result['breakdown']['distance']['cost']);
    }

    public function test_minimum_fare_is_enforced(): void
    {
        // Tiny job falls below the £15 Executive minimum.
        $result = app(PricingRuleEngine::class)->quote(
            $this->executive(), miles: 1, minutes: 3, pickupAt: Carbon::parse('2026-06-15 14:00')
        );

        $this->assertEquals(15.0, $result['total']);
    }

    public function test_night_uplift_applies_in_the_night_band(): void
    {
        $day = app(PricingRuleEngine::class)->quote($this->executive(), 10, 20, Carbon::parse('2026-06-15 14:00'));
        $night = app(PricingRuleEngine::class)->quote($this->executive(), 10, 20, Carbon::parse('2026-06-15 23:30'));

        $this->assertGreaterThan($day['total'], $night['total']);
        $this->assertGreaterThan(0, $night['breakdown']['night_uplift']);
    }

    public function test_bank_holiday_surcharge_applies(): void
    {
        // Christmas Day 2026 carries a 30% surcharge.
        $normal = app(PricingRuleEngine::class)->quote($this->executive(), 10, 20, Carbon::parse('2026-06-15 14:00'));
        $xmas = app(PricingRuleEngine::class)->quote($this->executive(), 10, 20, Carbon::parse('2026-12-25 14:00'));

        $this->assertGreaterThan($normal['total'], $xmas['total']);
        $this->assertEquals('Christmas Day', $xmas['breakdown']['bank_holiday']['name']);
    }

    public function test_engine_persists_a_quote_using_rule_price_when_ai_unavailable(): void
    {
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $this->executive()->id,
            'pickup_address' => 'Sheffield',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => '2026-06-15 14:00',
            'distance_miles' => 10,
            'duration_minutes' => 20,
        ]);

        $this->assertInstanceOf(Quote::class, $quote);
        $this->assertFalse($quote->ai_generated);
        $this->assertEquals(36.0, (float) $quote->price);
        $this->assertEquals(36.0, $quote->breakdown['rule_price']);
    }

    public function test_ai_price_is_used_and_clamped_when_available(): void
    {
        // Stub the Anthropic client: pretend it's configured and returns an
        // extreme price that must be clamped to +50% of the rule price (36 → 54).
        $fake = Mockery::mock(AnthropicService::class);
        $fake->shouldReceive('configured')->andReturnTrue();
        $fake->shouldReceive('model')->andReturn('claude-opus-4-8');
        $fake->shouldReceive('completeJson')->andReturn(['price' => 999, 'rationale' => 'Peak demand']);
        $this->app->instance(AnthropicService::class, $fake);

        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $this->executive()->id,
            'pickup_address' => 'Sheffield',
            'destination_address' => 'MAN',
            'pickup_at' => '2026-06-15 14:00',
            'distance_miles' => 10,
            'duration_minutes' => 20,
        ]);

        $this->assertTrue($quote->ai_generated);
        $this->assertEquals(54.0, (float) $quote->price); // clamped to 1.5 × 36
        $this->assertEquals('Peak demand', $quote->breakdown['ai_rationale']);
    }

    public function test_admin_can_generate_a_quote_via_http(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('quotes.store'), [
            'vehicle_type_id' => $this->executive()->id,
            'pickup_address' => 'Sheffield',
            'destination_address' => 'MAN',
            'pickup_at' => '2026-06-15 14:00',
            'distance_miles' => 10,
            'duration_minutes' => 20,
        ])->assertRedirect();

        $this->assertEquals(1, Quote::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
