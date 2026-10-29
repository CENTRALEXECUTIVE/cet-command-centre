<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pricing\AiPricingEngine;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quote extras — the CET surcharge list (mirrors ETO's Item Surcharge): meet &
 * greet, child/booster/infant seats and stopovers are added to the fare and
 * itemised in the breakdown.
 */
class QuoteExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_extras_are_added_and_itemised(): void
    {
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            // An airport PICKUP — meet & greet only ever applies to an arrival.
            'pickup_address' => 'Manchester Airport',
            'destination_address' => 'Sheffield S10',
            'pickup_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            'distance_miles' => 40, 'duration_minutes' => 55,
            'meet_greet' => 1,
            'child_seats' => 1,
            'stopovers' => 2,
            'stopover_addresses' => "12 Ecclesall Road, Sheffield\nHathersage",
        ]);

        // £10 meet & greet + £10 child seat + 2 × £10 stopovers = £40 of extras.
        $this->assertSame(40.0, (float) $quote->breakdown['extras_total']);
        $labels = array_column($quote->breakdown['extras'], 'label');
        $this->assertContains('Meet & greet', $labels);
        $this->assertContains('Child seat', $labels);
        $this->assertContains('Stopover (via) × 2', $labels);
        $this->assertSame("12 Ecclesall Road, Sheffield\nHathersage", $quote->breakdown['stopover_addresses']);

        // The final price includes the extras on top of the rule price.
        $this->assertSame(round($quote->breakdown['rule_price'] + 40.0, 2), (float) $quote->price);
    }

    public function test_quote_without_extras_is_unchanged(): void
    {
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'pickup_address' => 'Sheffield S10',
            'destination_address' => 'Leeds',
            'pickup_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            'distance_miles' => 33, 'duration_minutes' => 50,
        ]);

        $this->assertSame(0.0, (float) $quote->breakdown['extras_total']);
        $this->assertSame((float) $quote->breakdown['rule_price'], (float) $quote->price);
    }

    public function test_the_engine_prices_extras_and_stopovers(): void
    {
        // The standalone quote tool was retired, but the AI engine still prices
        // extras + stopovers (it's the same extras maths the booking form uses).
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'pickup_address' => 'Manchester Airport',
            'destination_address' => 'Sheffield S10',
            'pickup_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'distance_miles' => 40, 'duration_minutes' => 55,
            'meet_greet' => 1, 'stopovers' => 1,
            'stopover_addresses' => 'Ecclesall Road, Sheffield',
        ]);

        $this->assertSame(20.0, (float) $quote->breakdown['extras_total']); // £10 M&G + £10 stopover
    }

    public function test_meet_and_greet_is_never_priced_on_a_drop_off_to_the_airport(): void
    {
        // Sprotbrough home → East Midlands Airport is a DEPARTURE: a ticked meet &
        // greet must not add £10 (the £190-instead-of-£180 incident).
        $quote = app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'pickup_address' => '28a Park Drive, Sprotbrough, Doncaster DN5 7LA',
            'destination_address' => 'East Midlands Airport (EMA)',
            'pickup_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            'distance_miles' => 60, 'duration_minutes' => 70,
            'meet_greet' => 1,
        ]);

        $this->assertSame(0.0, (float) $quote->breakdown['extras_total']);
        $this->assertNotContains('Meet & greet', array_column($quote->breakdown['extras'], 'label'));
    }
}
