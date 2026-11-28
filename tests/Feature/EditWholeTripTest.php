<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors, 28 Nov 2026: "When I click edit I should be able to edit everything, outbound or return." */
class EditWholeTripTest extends TestCase
{
    use RefreshDatabase;

    private function trip(): array
    {
        $v = VehicleType::create(['name' => 'Executive', 'slug' => 'executive', 'passenger_capacity' => 4, 'luggage_capacity' => 2, 'is_active' => true]);
        $c = Customer::factory()->create(['name' => 'Laura Ann Lawhern', 'phone' => '+447949726109', 'email' => 'lauraann@jointmedica.com']);
        $o = Booking::factory()->create(['customer_id' => $c->id, 'vehicle_type_id' => $v->id, 'quoted_price' => 105, 'final_price' => null,
            'reference' => 'CET-T1A', 'pickup_address' => '1 Totley Mews, Sheffield S17 4BG', 'destination_address' => 'Manchester Airport (MAN), Terminal 2',
            'pickup_at' => '2026-12-08 10:00:00', 'is_return_leg' => false, 'journey_type' => 'return']);
        $r = Booking::factory()->create(['customer_id' => $c->id, 'vehicle_type_id' => $v->id, 'quoted_price' => 115, 'final_price' => null,
            'reference' => 'CET-T1B', 'pickup_address' => 'Manchester Airport (MAN), Terminal 2', 'destination_address' => '1 Totley Mews, Sheffield S17 4BG',
            'pickup_at' => '2026-12-13 10:45:00', 'is_return_leg' => true, 'journey_type' => 'return', 'flight_number' => 'AA100',
            'linked_booking_id' => $o->id]);
        $o->forceFill(['linked_booking_id' => $r->id])->save();

        return [$v, $o, $r];
    }

    private function form(VehicleType $v, array $o = []): array
    {
        return array_merge([
            'customer_name' => 'Laura Ann Lawhern', 'lead_name' => 'Matt Cannell', 'customer_phone' => '+447949726109',
            'customer_email' => 'lauraann@jointmedica.com', 'journey_type' => 'return',
            'pickup_address' => '1 Totley Mews, Sheffield S17 4BG', 'destination_address' => 'Manchester Airport (MAN), Terminal 2',
            'pickup_at' => '2026-12-08T10:00', 'vehicle_type_id' => $v->id, 'passengers' => 1, 'payment_method' => 'card',
            'quoted_price' => '220.00', 'leg_price_out' => '105.00', 'leg_price_return' => '115.00',
            'return_pickup_at' => '2026-12-13T10:45', 'return_pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'return_destination_address' => '1 Totley Mews, Sheffield S17 4BG', 'return_flight_number' => 'AA100',
        ], $o);
    }

    public function test_edit_opens_the_whole_trip_with_the_return_filled_in(): void
    {
        [, $o, $r] = $this->trip();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.edit', $r))->assertRedirect(route('bookings.edit', $o));
        $html = $this->actingAs($admin)->get(route('bookings.edit', $o))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="return"\s+selected/', $html);
        $this->assertStringContainsString('2026-12-13T10:45', $html);
        $this->assertStringContainsString('AA100', $html);
        $this->assertStringContainsString('value="220.00"', $html);
    }

    public function test_saving_updates_both_legs_and_keeps_each_legs_price(): void
    {
        [$v, $o, $r] = $this->trip();
        $count = Booking::count();

        $this->actingAs(User::factory()->admin()->create())->put(route('bookings.update', $o), $this->form($v, [
            'return_pickup_at' => '2026-12-14T16:30', 'return_flight_number' => 'BA222',
        ]))->assertRedirect(route('bookings.show', $o));

        $this->assertSame($count, Booking::count());
        $o->refresh(); $r->refresh();
        $this->assertSame('2026-12-08 10:00', $o->pickup_at->format('Y-m-d H:i'));
        $this->assertSame('2026-12-14 16:30', $r->pickup_at->format('Y-m-d H:i'));
        $this->assertSame('BA222', $r->flight_number);
        $this->assertSame(105.0, (float) $o->fareAmount());
        $this->assertSame(115.0, (float) $r->fareAmount());
        $this->assertStringContainsString('Manchester Airport', $r->pickup_address);
    }

    public function test_changing_the_total_and_return_price(): void
    {
        [$v, $o, $r] = $this->trip();
        $this->actingAs(User::factory()->admin()->create())->put(route('bookings.update', $o),
            $this->form($v, ['quoted_price' => '250.00', 'leg_price_return' => '130.00']))->assertRedirect();
        $this->assertSame(120.0, (float) $o->fresh()->fareAmount());
        $this->assertSame(130.0, (float) $r->fresh()->fareAmount());
    }

    public function test_picking_return_on_a_one_way_adds_the_return_leg(): void
    {
        [$v, $o, $r] = $this->trip();
        $r->forceDelete();
        $o->forceFill(['linked_booking_id' => null, 'reference' => 'CET-ONE1A'])->save();

        $this->actingAs(User::factory()->admin()->create())->put(route('bookings.update', $o),
            $this->form($v, ['leg_price_return' => '115.00']))->assertRedirect();

        $o->refresh();
        $new = Booking::find($o->linked_booking_id);
        $this->assertNotNull($new);
        $this->assertTrue((bool) $new->is_return_leg);
        $this->assertSame('CET-ONE1B', $new->reference);
        $this->assertSame('2026-12-13 10:45', $new->pickup_at->format('Y-m-d H:i'));
        $this->assertSame(115.0, (float) $new->fareAmount());
        $this->assertSame(105.0, (float) $o->fareAmount());
    }
}
