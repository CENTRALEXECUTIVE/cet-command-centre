<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directors, 20 Nov 2026: "That edit booking section needs to be rebuilt like
 * admin booking section exactly the same" — Edit now renders the New booking form
 * (live prices, Use £X re-quote), filled from the booking, and saves in place.
 */
class EditBookingOnNewFormTest extends TestCase
{
    use RefreshDatabase;

    private function exec(): VehicleType
    {
        return VehicleType::create(['name' => 'Executive', 'slug' => 'executive', 'passenger_capacity' => 4, 'luggage_capacity' => 2, 'is_active' => true]);
    }

    public function test_edit_shows_the_new_booking_form_filled_from_the_booking(): void
    {
        $v = $this->exec();
        $c = Customer::factory()->create(['name' => 'Claire Green', 'email' => 'claire@example.com']);
        $b = Booking::factory()->create([
            'customer_id' => $c->id, 'vehicle_type_id' => $v->id, 'quoted_price' => 175, 'final_price' => 115,
            'pickup_address' => 'Manchester Airport (MAN), T2', 'destination_address' => 'Unit 3A, Tinsley, Sheffield',
            'flight_number' => 'EW9342', 'meta' => ['lead_name' => 'Matthias Perez', 'booked_by' => 'Claire Green', 'meet_greet' => true],
        ]);

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('bookings.edit', $b))->assertOk()->getContent();

        $this->assertStringContainsString('Edit booking', $html);
        $this->assertStringContainsString($b->reference, $html);
        $this->assertStringContainsString('id="veh-cards"', $html);          // live price cards from New booking
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
        $this->assertStringContainsString('Save changes', $html);
        $this->assertStringNotContainsString('name="privacy_consent"', $html);
        $this->assertStringNotContainsString('name="email_customer"', $html);
        $this->assertStringContainsString('value="Claire Green"', $html);     // booker
        $this->assertStringContainsString('value="Matthias Perez"', $html);   // passenger
        $this->assertStringContainsString('value="115.00"', $html);           // what the job costs now
        $this->assertStringContainsString('EW9342', $html);
    }

    public function test_saving_a_new_flight_and_airport_updates_the_same_booking(): void
    {
        $v = $this->exec();
        $c = Customer::factory()->create(['name' => 'Sam Rider', 'phone' => '+447700900123']);
        $b = Booking::factory()->create(['customer_id' => $c->id, 'vehicle_type_id' => $v->id, 'quoted_price' => 120,
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => '1 High St, Sheffield S1 2AB',
            'pickup_at' => '2026-11-21 18:00:00', 'flight_number' => 'BA100']);
        $count = Booking::count();

        $this->actingAs(User::factory()->admin()->create())->put(route('bookings.update', $b), [
            'customer_name' => 'Sam Rider', 'customer_phone' => '+447700900123',
            'journey_type' => 'one_way',
            'pickup_address' => 'Leeds Bradford Airport (LBA), Leeds', 'pickup_postcode' => 'LS19 7TU',
            'destination_address' => '1 High St, Sheffield S1 2AB',
            'pickup_at' => '2026-11-21T18:00', 'flight_landing_at' => '2026-11-21T21:55',
            'flight_number' => 'KL1025', 'vehicle_type_id' => $v->id, 'passengers' => 1,
            'payment_method' => 'card', 'quoted_price' => '135.00',
        ])->assertRedirect(route('bookings.show', $b));

        $this->assertSame($count, Booking::count()); // updated in place, nothing new
        $b->refresh();
        $this->assertSame('2026-11-21 21:55', $b->pickup_at->format('Y-m-d H:i')); // landing time = pickup, UK time
        $this->assertSame('KL1025', $b->flight_number);
        $this->assertStringContainsString('Leeds Bradford', $b->pickup_address);
        $this->assertSame('LS19 7TU', $b->pickup_postcode);
        $this->assertSame(135.0, $b->fareAmount());
    }

    public function test_booker_and_passenger_stay_separate_on_save(): void
    {
        $v = $this->exec();
        $c = Customer::factory()->create(['name' => 'Claire Green']);
        $b = Booking::factory()->create(['customer_id' => $c->id, 'vehicle_type_id' => $v->id, 'quoted_price' => 115,
            'meta' => ['lead_name' => 'Matthias Perez', 'booked_by' => 'Claire Green']]);

        $this->actingAs(User::factory()->admin()->create())->put(route('bookings.update', $b), [
            'customer_name' => 'Claire Green', 'lead_name' => 'Matthias Perez y Schmidt',
            'pickup_address' => $b->pickup_address, 'destination_address' => $b->destination_address,
            'pickup_at' => $b->pickup_at->format('Y-m-d\TH:i'), 'vehicle_type_id' => $v->id, 'passengers' => 1,
            'payment_method' => 'card', 'quoted_price' => '115',
        ])->assertRedirect();

        $b->refresh();
        $this->assertSame('Matthias Perez y Schmidt', $b->meta['lead_name']);
        $this->assertSame('Claire Green', $b->meta['booked_by']);
        $this->assertSame('Claire Green', $b->customer->fresh()->name);
    }

    public function test_vat_stays_on_once_paid_and_company_details_update(): void
    {
        $v = $this->exec();
        $b = Booking::factory()->create(['vehicle_type_id' => $v->id, 'quoted_price' => 115,
            'meta' => ['vat_invoice_requested' => true, 'billing_entity' => 'transfers', 'vat_details' => ['company' => 'Old Co']]]);
        $b->markFarePaid('sq_1', 138.00, 'transfers');
        $base = ['customer_name' => 'X Y', 'pickup_address' => $b->pickup_address, 'destination_address' => $b->destination_address,
            'pickup_at' => $b->pickup_at->format('Y-m-d\TH:i'), 'vehicle_type_id' => $v->id, 'passengers' => 1,
            'payment_method' => 'card', 'quoted_price' => '115'];
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('bookings.update', $b), $base + ['vat_invoice' => '1', 'vat_company' => 'JELD-WEN UK Ltd'])->assertRedirect();
        $this->assertSame('JELD-WEN UK Ltd', $b->fresh()->meta['vat_details']['company']);

        $this->actingAs($admin)->put(route('bookings.update', $b), $base + ['vat_invoice' => '0'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'VAT invoice left ON'));
        $this->assertTrue($b->fresh()->vatInvoiceRequested());
    }
}
