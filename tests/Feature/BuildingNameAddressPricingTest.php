<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use App\Services\Pricing\DistanceService;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 27 Nov 2026: a building name kept in front of the street must never change the price. */
class BuildingNameAddressPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_building_name_in_front_does_not_change_the_fixed_fare(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $q = app(QuoteService::class);
        foreach (VehicleType::whereIn('slug', ['executive', 'v-class'])->get() as $vt) {
            $plain = $q->quote('Woodmere Drive, Old Whittington, Chesterfield S41 9TA, UK', 'Manchester Airport T2, Manchester M90 1QX', $vt);
            $named = $q->quote('23 Brearley Hall, Woodmere Drive, Old Whittington, Chesterfield S41 9TA, UK', 'Manchester Airport T2, Manchester M90 1QX', $vt);
            $this->assertNotNull($plain['price']);
            $this->assertSame($plain['price'], $named['price'], $vt->slug.': same fare with the building name');
        }
    }

    public function test_google_retries_with_the_postcode_when_it_cannot_place_the_full_address(): void
    {
        \App\Models\Setting::set('google_maps_key', 'test-key', 'string', 'maps');
        $calls = [];
        Http::fake(function ($req) use (&$calls) {
            $calls[] = $req['origin']['address'] ?? '';
            // Google can't place the building name; it can place the postcode.
            return str_contains($calls[count($calls) - 1], 'Brearley')
                ? Http::response(['routes' => []], 200)
                : Http::response(['routes' => [['distanceMeters' => 80467, 'duration' => '4800s']]], 200);
        });
        $r = app(DistanceService::class)->resolve('23 Brearley Hall, Woodmere Drive, Old Whittington, Chesterfield S41 9TA, UK', 'Manchester Airport, M90 1QX');
        if (! app(DistanceService::class)->configured()) {
            $this->markTestSkipped('Maps key setting not available in this schema');
        }
        $this->assertSame('google', $r['source']);
        $this->assertEqualsWithDelta(50.0, $r['miles'], 0.5);
        $this->assertContains('S41 9TA, UK', $calls);
    }
}
