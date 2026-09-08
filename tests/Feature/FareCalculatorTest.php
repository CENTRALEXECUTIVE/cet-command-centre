<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use App\Services\Pricing\FareCalculator;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The public fare: base fixed/free-roam price, then a holiday/rush multiplier,
 * then itemised extras (meet & greet, seats, stops, ribbons, hourly hire).
 */
class FareCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config([
            'cet.vat_registered' => true,
            'cet.holiday_surcharges' => [
                ['label' => 'Christmas Day', 'factor' => 1.3, 'from' => '2026-12-25 00:00', 'to' => '2026-12-25 23:59'],
            ],
        ]);
    }

    private function calc(): FareCalculator
    {
        return app(FareCalculator::class);
    }

    private function executive(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->firstOrFail();
    }

    public function test_a_normal_day_has_no_surcharge(): void
    {
        $fare = $this->calc()->calculate('Sheffield S1', 'Manchester Airport', $this->executive(),
            Carbon::parse('2026-11-10 09:00'));

        $this->assertSame(110.0, $fare['base']);      // new fixed rate
        $this->assertNull($fare['surcharge']);
        $this->assertSame(110.0, $fare['subtotal']);
    }

    public function test_a_holiday_multiplies_the_base(): void
    {
        $fare = $this->calc()->calculate('Sheffield S1', 'Manchester Airport', $this->executive(),
            Carbon::parse('2026-12-25 10:00'));

        $this->assertSame('Christmas Day', $fare['surcharge']['label']);
        $this->assertSame(33.0, $fare['surcharge']['amount']); // 110 × 0.3
        $this->assertSame(143.0, $fare['subtotal']);           // 110 + 33
    }

    public function test_extras_are_itemised_and_added(): void
    {
        $fare = $this->calc()->calculate('Sheffield S1', 'Manchester Airport', $this->executive(),
            Carbon::parse('2026-11-10 09:00'),
            ['meet_greet' => true, 'child_seats' => 2, 'stopovers' => 1]);

        // £10 meet & greet + 2×£10 seats + 1×£10 stop = £40.
        $this->assertSame(40.0, $fare['extras_total']);
        $this->assertSame(150.0, $fare['subtotal']); // 110 + 40
        $this->assertCount(3, $fare['extras']);
    }

    public function test_price_on_request_vehicle_returns_no_base(): void
    {
        $luxury = VehicleType::where('slug', 'rolls-royce-ghost')->first()
            ?? VehicleType::where('name', 'like', '%Luxury%')->first();
        if (! $luxury) {
            $this->markTestSkipped('No luxury vehicle seeded.');
        }

        $fare = $this->calc()->calculate('Sheffield S1', 'Bakewell', $luxury, Carbon::parse('2026-11-10 09:00'));
        $this->assertTrue($fare['poa']);
        $this->assertNull($fare['subtotal']);
    }
}
