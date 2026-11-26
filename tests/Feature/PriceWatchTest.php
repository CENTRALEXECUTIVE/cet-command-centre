<?php

namespace Tests\Feature;

use App\Models\WatchdogEvent;
use App\Services\Pricing\DistanceService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 24/7 website price watch: quiet when right, alerts the directors when wrong. */
class PriceWatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.base.address' => 'Sheffield, UK']);
    }

    public function test_all_standard_journeys_price_and_nothing_is_alerted(): void
    {
        $this->artisan('cet:price-watch')->assertExitCode(0);
        $this->assertSame(0, WatchdogEvent::where('event_type', 'price_watch')->count());
    }

    public function test_google_not_answering_alerts_the_directors_once(): void
    {
        $m = \Mockery::mock(DistanceService::class)->makePartial();
        $m->shouldReceive('configured')->andReturn(true);
        $m->shouldReceive('resolve')->andReturn(['miles' => 10, 'minutes' => 20, 'source' => 'estimate']);
        $this->app->instance(DistanceService::class, $m);

        $this->artisan('cet:price-watch')->assertExitCode(1);
        $this->artisan('cet:price-watch')->assertExitCode(1);
        $ev = WatchdogEvent::where('event_type', 'price_watch')->get();
        $this->assertCount(1, $ev, 'once an hour, not every run');
        $this->assertSame('critical', $ev->first()->severity);
    }
}
