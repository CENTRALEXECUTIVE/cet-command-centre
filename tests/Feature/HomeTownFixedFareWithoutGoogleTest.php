<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use App\Services\Pricing\DistanceService;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 26 Nov 2026: a JELD-WEN Manchester Airport → Sheffield return became a quote request
 * because Google didn't measure a Sheffield address with no postcode. An address that
 * names a home town as its locality keeps its fixed fare; far places still go to the office.
 */
class HomeTownFixedFareWithoutGoogleTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_home_town_keeps_the_fixed_fare_when_google_cannot_measure(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.base.address' => 'Sheffield, UK']);
        $m = \Mockery::mock(DistanceService::class)->makePartial();
        $m->shouldReceive('configured')->andReturn(true);
        $m->shouldReceive('resolve')->andReturn(['miles' => 10, 'minutes' => 20, 'source' => 'estimate']);
        $this->app->instance(DistanceService::class, $m);

        $q = app(QuoteService::class);
        $exec = VehicleType::where('slug', 'executive')->first();
        $man = 'Manchester Airport (MAN), Manchester M90 1QX, UK';

        foreach (['Leopold Hotel, Leopold Street, Sheffield, UK', 'JELD-WEN UK, Bessemer Park, Shepcote Lane, Sheffield, UK', 'Sheffield, UK', 'Rotherham, UK'] as $local) {
            $this->assertEquals(105.0, $q->quote($man, $local, $exec)['price'], $local);
            $this->assertEquals(105.0, $q->quote($local, $man, $exec)['price'], 'reverse: '.$local);
        }
        foreach (['Sheffield Road, Birmingham, UK', 'Huddersfield, UK'] as $far) {
            $this->assertNull($q->quote($man, $far, $exec)['price'], $far);
        }
    }
}
