<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin booking form's per-vehicle price strip must include the via-stop fee
 * and the ticked extras — the bug where an admin quote with a Sheffield stop came
 * out with no stop fee (the strip used to show the base fare only).
 */
class AdminPricingStripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function execPrice(array $options): float
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        return (float) collect($options)->firstWhere('id', $exec->id)['price'];
    }

    public function test_strip_adds_the_via_stop_fee(): void
    {
        $admin = User::factory()->admin()->create();

        $base = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport',
        ])->assertOk()->json('options');
        $this->assertEqualsWithDelta(105.0, $this->execPrice($base), 0.001); // ETO fixed base

        $withStop = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport', 'stops' => 1,
        ])->assertOk()->json('options');
        $this->assertEqualsWithDelta(115.0, $this->execPrice($withStop), 0.001); // + £10 stop

        $twoStops = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport', 'stops' => 2,
        ])->assertOk()->json('options');
        $this->assertEqualsWithDelta(125.0, $this->execPrice($twoStops), 0.001); // + 2 × £10
    }

    public function test_strip_adds_the_ticked_extras_on_top_of_the_stop(): void
    {
        $admin = User::factory()->admin()->create();

        $options = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport',
            'stops' => 1, 'meet_greet' => true, 'child_seats' => 1,
        ])->assertOk()->json('options');

        // 105 base + £10 stop + £10 meet&greet + £10 child seat = £135.
        $this->assertEqualsWithDelta(135.0, $this->execPrice($options), 0.001);
    }

    public function test_strip_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->postJson(route('pricing.strip'), [
            'pickup' => 'A', 'destination' => 'B',
        ])->assertForbidden();
    }
}
