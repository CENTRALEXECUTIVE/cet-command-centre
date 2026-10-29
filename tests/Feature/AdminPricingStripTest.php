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

    public function test_a_return_with_different_addresses_prices_each_leg_separately(): void
    {
        $admin = User::factory()->admin()->create();

        // Straight reverse: Sheffield ↔ Manchester Airport = £105 each way = £210.
        $reverse = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport',
            'journey_type' => 'return',
        ])->assertOk()->json('options');
        $this->assertEqualsWithDelta(210.0, $this->execPrice($reverse), 0.001);

        // DIFFERENT return drop-off (Chesterfield = £110 from Manchester): the return
        // leg re-prices on its own route — £105 out + £110 back = £215, not £210.
        $different = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport',
            'journey_type' => 'return',
            'return_pickup' => 'Manchester Airport',
            'return_destination' => 'Chesterfield',
        ])->assertOk()->json('options');
        $this->assertEqualsWithDelta(215.0, $this->execPrice($different), 0.001);
    }

    public function test_meet_and_greet_is_only_charged_on_the_airport_arrival_leg_of_a_return(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        // JELD-WEN, Oct 2026: MAN T2 → office, then office → Ibis Birmingham Airport.
        // Meet & greet belongs to the Manchester arrival only — the office pickup
        // must not carry it (the Birmingham leg showed £160 instead of £150).
        $options = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Manchester Airport Terminal 2', 'destination' => 'Shepcote Lane, Sheffield S9 1DZ',
            'journey_type' => 'return', 'meet_greet' => true,
            'return_pickup' => 'Shepcote Lane, Sheffield S9 1DZ',
            'return_destination' => 'Ibis Birmingham Airport, Ambassador Road, Bickenhill B26 3AW',
        ])->assertOk()->json('options');

        $legs = collect(collect($options)->firstWhere('id', $exec->id)['breakdown'])->pluck('amount', 'label');
        $oneWayOut = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Manchester Airport Terminal 2', 'destination' => 'Shepcote Lane, Sheffield S9 1DZ', 'meet_greet' => true,
        ])->json('options');
        $oneWayBack = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Shepcote Lane, Sheffield S9 1DZ', 'destination' => 'Ibis Birmingham Airport, Ambassador Road, Bickenhill B26 3AW',
        ])->json('options');

        // Outbound = the airport fare WITH meet & greet; return = the plain fare.
        $this->assertEqualsWithDelta($this->execPrice($oneWayOut), (float) $legs['Outbound leg'], 0.001);
        $this->assertEqualsWithDelta($this->execPrice($oneWayBack), (float) $legs['Return leg'], 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $legs['Return leg'], 0.001); // Sheffield → Birmingham Airport
    }

    public function test_meet_and_greet_is_never_charged_on_a_one_way_drop_off_to_the_airport(): void
    {
        $admin = User::factory()->admin()->create();

        // Oct 2026: 28a Park Drive, Sprotbrough, Doncaster → East Midlands Airport showed
        // £190 (£180 + £10 meet & greet). A departure never carries meet & greet, even
        // if the box was ticked.
        $ticked = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => '28a Park Drive, Sprotbrough, Doncaster DN5 7LA, UK',
            'destination' => 'East Midlands Airport (EMA), Castle Donington, Derby, UK',
            'meet_greet' => true,
        ])->assertOk()->json('options');
        $plain = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => '28a Park Drive, Sprotbrough, Doncaster DN5 7LA, UK',
            'destination' => 'East Midlands Airport (EMA), Castle Donington, Derby, UK',
        ])->assertOk()->json('options');

        $this->assertEqualsWithDelta($this->execPrice($plain), $this->execPrice($ticked), 0.001);
    }

    public function test_meet_and_greet_is_charged_on_a_pickup_from_a_private_jet_handler_at_the_airport(): void
    {
        $admin = User::factory()->admin()->create();

        // Kerry's private flights land at the EMA flight-support cabin — that's an
        // airport arrival, so meet & greet applies.
        $ticked = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Advantage Flight Support Ltd, Derby DE74 2SA, UK',
            'destination' => '28a Park Drive, Sprotbrough, Doncaster DN5 7LA, UK',
            'meet_greet' => true,
        ])->assertOk()->json('options');
        $plain = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Advantage Flight Support Ltd, Derby DE74 2SA, UK',
            'destination' => '28a Park Drive, Sprotbrough, Doncaster DN5 7LA, UK',
        ])->assertOk()->json('options');

        $this->assertEqualsWithDelta($this->execPrice($plain) + 10.0, $this->execPrice($ticked), 0.001);

        // Charged, but called an airport pickup — no "meet & greet / name board" there.
        $labels = array_column(collect($ticked)->firstWhere('id', VehicleType::where('slug', 'executive')->value('id'))['breakdown'], 'label');
        $this->assertContains('Airport pickup', $labels);
        $this->assertNotContains('Meet & greet', $labels);
    }

    public function test_strip_adds_the_ticked_extras_on_top_of_the_stop(): void
    {
        $admin = User::factory()->admin()->create();

        // An ARRIVAL (airport pickup), where meet & greet applies.
        $options = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Manchester Airport', 'destination' => 'Sheffield S1',
            'stops' => 1, 'meet_greet' => true, 'child_seats' => 1,
        ])->assertOk()->json('options');

        // 105 base + £10 stop + £10 meet&greet + £10 child seat = £135.
        $this->assertEqualsWithDelta(135.0, $this->execPrice($options), 0.001);
    }

    public function test_strip_returns_an_itemised_breakdown(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $options = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Manchester Airport', 'destination' => 'Sheffield S1',
            'stops' => 1, 'meet_greet' => true,
        ])->assertOk()->json('options');

        $execOpt = collect($options)->firstWhere('id', $exec->id);
        $labels = array_column($execOpt['breakdown'], 'label');

        $this->assertContains('Base fare', $labels);
        // The via stop and the meet & greet each show as their own priced line.
        $this->assertTrue(
            (bool) collect($execOpt['breakdown'])->first(fn ($l) => stripos($l['label'], 'stop') !== false),
            'the via-stop line is itemised'
        );
        $this->assertTrue(
            (bool) collect($execOpt['breakdown'])->first(fn ($l) => stripos($l['label'], 'meet') !== false),
            'the meet & greet line is itemised'
        );
        // The lines add up to the quoted total.
        $sum = array_sum(array_column($execOpt['breakdown'], 'amount'));
        $this->assertEqualsWithDelta($execOpt['price'], $sum, 0.001);
    }

    public function test_strip_prices_a_return_as_both_legs(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $oneWay = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport',
        ])->assertOk()->json('options');
        $this->assertEqualsWithDelta(105.0, $this->execPrice($oneWay), 0.001);

        $return = $this->actingAs($admin)->postJson(route('pricing.strip'), [
            'pickup' => 'Sheffield S1', 'destination' => 'Manchester Airport',
            'journey_type' => 'return',
        ])->assertOk()->json('options');

        // A return is both legs: 105 out + 105 back = £210, not the one-way £105.
        $this->assertEqualsWithDelta(210.0, $this->execPrice($return), 0.001);

        // And the breakdown names the two legs so the double price is obvious.
        $labels = array_column(collect($return)->firstWhere('id', $exec->id)['breakdown'], 'label');
        $this->assertContains('Outbound leg', $labels);
        $this->assertContains('Return leg', $labels);
    }

    public function test_strip_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->postJson(route('pricing.strip'), [
            'pickup' => 'A', 'destination' => 'B',
        ])->assertForbidden();
    }
}
