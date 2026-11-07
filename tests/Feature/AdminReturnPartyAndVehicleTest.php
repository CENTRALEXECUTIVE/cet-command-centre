<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Return step (Nov 2026): the return leg follows the outbound's party,
 * luggage and vehicle unless the office changes them for the way back.
 */
class AdminReturnPartyAndVehicleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'customer_name' => 'Jane Traveller', 'customer_phone' => '07700900123', 'customer_email' => 'jane@example.com',
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'journey_type' => 'return',
            'pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(9)->format('Y-m-d\TH:i'),
            'pickup_address' => '41 Westbourne Road, Sheffield S10 2QT',
            'destination_address' => 'Manchester Airport, Manchester, UK',
            'passengers' => 2, 'suitcases' => 2, 'hand_luggage' => 1,
            'payment_method' => 'card', 'quoted_price' => 220.00,
            'leg_price_out' => 105.00, 'leg_price_return' => 115.00,
            'meet_greet' => '1', 'privacy_consent' => '1',
        ], $o);
    }

    private function legs(): array
    {
        return [Booking::where('is_return_leg', false)->firstOrFail(), Booking::where('is_return_leg', true)->firstOrFail()];
    }

    public function test_a_plain_return_copies_party_luggage_and_vehicle_and_charges_meet_greet_on_the_airport_pickup(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('bookings.store'), $this->payload([
            // The form sends the followed values (same as the outbound).
            'return_passengers' => 2, 'return_suitcases' => 2, 'return_hand_luggage' => 1, 'return_vehicle_type_id' => '',
        ]))->assertRedirect();

        [$out, $ret] = $this->legs();
        $this->assertSame(2, $ret->passengers);
        $this->assertSame(2, (int) $ret->meta['suitcases']);
        $this->assertSame(1, (int) $ret->meta['hand_luggage']);
        $this->assertSame($out->vehicle_type_id, $ret->vehicle_type_id);
        $this->assertEquals(105.0, (float) $out->quoted_price);
        $this->assertEquals(115.0, (float) $ret->quoted_price);
        $this->assertTrue($ret->isMeetGreet(), 'return collects from the airport');
        $this->assertFalse($out->isMeetGreet(), 'outbound drops at the airport');
    }

    public function test_the_office_can_change_the_return_party_luggage_and_vehicle(): void
    {
        $vclass = VehicleType::where('slug', 'v-class')->value('id') ?? VehicleType::where('passenger_capacity', '>=', 6)->value('id');
        $this->actingAs(User::factory()->admin()->create())->post(route('bookings.store'), $this->payload([
            'return_passengers' => 5, 'return_suitcases' => 6, 'return_hand_luggage' => 3, 'return_vehicle_type_id' => $vclass,
        ]))->assertRedirect();

        [$out, $ret] = $this->legs();
        $this->assertSame(2, $out->passengers);
        $this->assertSame(2, (int) $out->meta['suitcases']);
        $this->assertSame(5, $ret->passengers);
        $this->assertSame(6, (int) $ret->meta['suitcases']);
        $this->assertSame(3, (int) $ret->meta['hand_luggage']);
        $this->assertSame(9, (int) $ret->luggage);
        $this->assertSame((int) $vclass, (int) $ret->vehicle_type_id);
        $this->assertNotSame($out->vehicle_type_id, $ret->vehicle_type_id);
    }

    public function test_switching_a_one_way_never_creates_a_return_from_leftover_return_fields(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('bookings.store'), $this->payload([
            'journey_type' => 'one_way', 'return_pickup_at' => '', 'return_passengers' => 4, 'return_vehicle_type_id' => '',
            'quoted_price' => 105.00, 'leg_price_out' => '', 'leg_price_return' => '',
        ]))->assertRedirect();

        $this->assertSame(1, Booking::count());
        $this->assertSame(2, Booking::first()->passengers);
    }

    public function test_return_party_is_validated(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('bookings.store'), $this->payload([
            'return_passengers' => 0,
        ]))->assertSessionHasErrors('return_passengers');
        $this->assertSame(0, Booking::count());
    }

    public function test_the_form_shows_the_return_party_fields(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.create'))->assertOk()
            ->assertSee('name="return_passengers"', false)
            ->assertSee('name="return_suitcases"', false)
            ->assertSee('name="return_hand_luggage"', false)
            ->assertSee('bk-ret-cards', false)
            // Step list: Outbound (journey + vehicle + luggage) then Return, and the
            // catch-all step is always the LAST one (a fixed index broke the page once).
            ->assertSee("{ title: 'Outbound', els: [locSec, vehSec, extrasSec] }", false)
            ->assertSee('steps[steps.length - 1].els.push(sec)', false)
            ->assertDontSee('steps[4].els', false);
    }
}
