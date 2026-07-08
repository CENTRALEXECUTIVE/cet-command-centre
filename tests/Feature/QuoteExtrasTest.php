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
            'pickup_address' => 'Sheffield S10',
            'destination_address' => 'Manchester Airport',
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

    public function test_quote_form_accepts_extras_and_stopover_addresses(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('quotes.store'), [
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'pickup_address' => 'Sheffield S10',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'distance_miles' => 40, 'duration_minutes' => 55,
            'meet_greet' => 1, 'stopovers' => 1,
            'stopover_addresses' => 'Ecclesall Road, Sheffield',
        ])->assertRedirect();

        $quote = \App\Models\Quote::latest('id')->first();
        $this->assertSame(20.0, (float) $quote->breakdown['extras_total']); // £10 M&G + £10 stopover
    }
}
