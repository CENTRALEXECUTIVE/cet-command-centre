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
 * The admin booking form can set DIFFERENT return-leg addresses (a return isn't
 * always a straight reverse), and can preview the customer confirmation email
 * before the booking is confirmed.
 */
class AdminReturnAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'John Mannix',
            'customer_phone' => '07700900123',
            'customer_email' => 'john@example.com',
            'vehicle_type_id' => VehicleType::first()->id,
            'journey_type' => 'return',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'pickup_address' => 'Sheffield Station',
            'destination_address' => 'Cavendish Hotel, Baslow',
            'passengers' => 2,
            'payment_method' => 'card',
            'quoted_price' => 110.00,
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_a_return_defaults_to_the_reverse_of_the_outbound(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        $return = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertSame('Cavendish Hotel, Baslow', $return->pickup_address);
        $this->assertSame('Sheffield Station', $return->destination_address);
    }

    public function test_the_office_can_set_different_return_addresses(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'return_pickup_address' => 'Chatsworth House, Bakewell',
                'return_destination_address' => 'Sheffield Airport Hotel',
            ]))
            ->assertRedirect();

        $return = Booking::where('is_return_leg', true)->firstOrFail();
        // The return uses the office's addresses, not a blind reverse of the outbound.
        $this->assertSame('Chatsworth House, Bakewell', $return->pickup_address);
        $this->assertSame('Sheffield Airport Hotel', $return->destination_address);

        // And the two legs are still linked as one trip.
        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $this->assertSame($out->id, $return->linked_booking_id);
        $this->assertSame($return->id, $out->linked_booking_id);
    }

    public function test_the_return_leg_keeps_its_own_vehicle_price_and_no_copied_airport_details(): void
    {
        // JELD-WEN, Oct 2026: MAN T2 arrival → office, then office → Ibis Birmingham
        // Airport on a different vehicle and the office's own return price.
        Mail::fake();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $vclass = VehicleType::where('slug', 'v-class')->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'vehicle_type_id' => $exec->id,
                'return_vehicle_type_id' => $vclass->id,
                'pickup_address' => 'Manchester Airport Terminal 2',
                'destination_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ',
                'return_pickup_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ',
                'return_destination_address' => 'Ibis Birmingham Airport, Bickenhill B26 3AW',
                'flight_number' => 'ib987', 'flight_landing_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
                'meet_greet' => '1',
                'quoted_price' => 275.00, 'leg_price_out' => 115.00, 'leg_price_return' => 160.00,
            ]))
            ->assertRedirect();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();

        // Each leg keeps its own price and vehicle.
        $this->assertEqualsWithDelta(115.0, (float) $out->quoted_price, 0.001);
        $this->assertEqualsWithDelta(160.0, (float) $ret->quoted_price, 0.001);
        $this->assertSame($exec->id, $out->vehicle_type_id);
        $this->assertSame($vclass->id, $ret->vehicle_type_id);

        // The arrival flight + meet & greet stay on the airport leg only.
        $this->assertSame('IB987', $out->flight_number);
        $this->assertNotEmpty($out->meta['meet_greet'] ?? null);
        $this->assertNull($ret->flight_number);
        $this->assertEmpty($ret->meta['meet_greet'] ?? null);
        $this->assertEmpty($ret->meta['flight_landing_at'] ?? null);
    }

    public function test_a_return_from_an_airport_takes_its_own_return_flight(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'pickup_address' => 'Sheffield S1 2BP', 'destination_address' => 'Manchester Airport Terminal 1',
                'flight_number' => 'EZY100', 'return_flight_number' => 'ezy101',
            ]))
            ->assertRedirect();

        $this->assertSame('EZY101', Booking::where('is_return_leg', true)->firstOrFail()->flight_number);
    }

    public function test_the_form_shows_the_return_vehicle_and_price_boxes(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.create'))->assertOk()
            ->assertSee('id="return_vehicle_type_id"', false)
            ->assertSee('id="return_leg_price"', false)
            ->assertSee('id="return_flight_number"', false);
    }

    public function test_the_confirmation_email_can_be_previewed_before_saving(): void
    {
        // Mirror live: Square (tips) configured, so the email builds a tip link —
        // which must NOT try to save the unsaved preview booking (the staging bug).
        config(['services.square.access_token' => 'sq', 'services.square.location_id' => 'LOC']);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload([
                'suitcases' => 2, 'hand_luggage' => 1,
            ]))
            ->assertOk();

        // It renders the real email from the entered details, and saves NOTHING.
        $res->assertSee('John Mannix', false);
        $res->assertSee('Cavendish Hotel, Baslow', false);
        // Luggage now shows (the bug: it was missing from the email entirely).
        $res->assertSee('2 suitcases', false);
        $res->assertSee('1 cabin bag', false);
        // A return previews as the ONE combined email: both journeys, each leg's
        // fare (£55 + £55) and the whole-trip total.
        $res->assertSee('Outbound journey', false);
        $res->assertSee('Return journey', false);
        $res->assertSee('55.00', false);
        $res->assertSee('Total (whole trip):', false);
        $res->assertSee('110.00', false);
        $res->assertSee('CET-PREVIEWA / CET-PREVIEWB', false);
        $this->assertSame(0, Booking::count(), 'previewing must not create a booking');
    }

    public function test_the_jeldwen_return_previews_with_both_journeys_and_vat(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $res = $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload([
                'vehicle_type_id' => $exec->id, 'vat_invoice' => '1',
                'pickup_address' => 'Manchester Airport Terminal 2', 'destination_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ',
                'return_pickup_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ',
                'return_destination_address' => 'Ibis Birmingham Airport, Bickenhill B26 3AW',
                'flight_number' => 'IB987', 'meet_greet' => '1',
                'quoted_price' => 265, 'leg_price_out' => 115, 'leg_price_return' => 150,
            ]))
            ->assertOk();

        config(['cet.vat_registered' => true]);
        $res->assertSee('Ibis Birmingham Airport', false);
        $res->assertSee('Total (whole trip):', false);
        $this->assertSame(1, substr_count($res->getContent(), 'driver waits in arrivals')); // outbound only
        $this->assertSame(0, Booking::count());
    }

    public function test_the_new_booking_preview_is_admin_only(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload())
            ->assertForbidden();
    }

    public function test_a_return_stores_each_leg_s_own_fare(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'quoted_price' => 110,
                'leg_price_out' => 55,
                'leg_price_return' => 55,
            ]))
            ->assertRedirect();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        // £55 on each leg — not £110 on the outbound and nothing on the return.
        $this->assertEqualsWithDelta(55.0, (float) $out->quoted_price, 0.01);
        $this->assertEqualsWithDelta(55.0, (float) $ret->quoted_price, 0.01);
        // The whole trip still totals £110.
        $this->assertEqualsWithDelta(110.0, (float) $out->invoiceGroupBookings()->sum(fn ($b) => (float) $b->fareGross()), 0.01);
    }

    public function test_a_custom_total_splits_in_the_quote_ratio(): void
    {
        Mail::fake();
        // Quote was 60/40; the office types a £120 total → split stays 60/40 = 72/48.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'quoted_price' => 120,
                'leg_price_out' => 60,
                'leg_price_return' => 40,
            ]))
            ->assertRedirect();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertEqualsWithDelta(72.0, (float) $out->quoted_price, 0.01);
        $this->assertEqualsWithDelta(48.0, (float) $ret->quoted_price, 0.01);
    }

    public function test_a_return_sends_one_confirmation_for_the_whole_trip(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'email_customer' => '1',
                'quoted_price' => 110, 'leg_price_out' => 55, 'leg_price_return' => 55,
            ]))
            ->assertRedirect();

        // ONE confirmation (the email lists both journeys + the trip total), from
        // the outbound leg, with a Pay now link — never two near-identical emails.
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BookingConfirmationMail::class, 1);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BookingConfirmationMail::class,
            fn ($m) => ! $m->booking->is_return_leg && $m->payUrl !== null);
        $this->assertSame(2, Booking::whereNotNull('meta->customer_email_sent')->count());
    }

    public function test_a_vat_return_puts_vat_on_both_legs_and_the_email_shows_the_full_vat(): void
    {
        // JELD-WEN, 28 Oct 2026: only the outbound got VAT, so the email read
        // £138 + £150 = £288 with "VAT £23" instead of £318 / VAT £53.
        Mail::fake();
        config(['cet.vat_registered' => true]);
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'vehicle_type_id' => $exec->id, 'vat_invoice' => '1', 'vat_company' => 'JELD-WEN UK Ltd',
                'quoted_price' => 265, 'leg_price_out' => 115, 'leg_price_return' => 150,
            ]))->assertRedirect();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertTrue($out->vatInvoiceRequested());
        $this->assertTrue($ret->vatInvoiceRequested());
        $this->assertSame('JELD-WEN UK Ltd', $ret->meta['vat_details']['company'] ?? null);
        // Any auto-made unpaid card line is VAT-inclusive, not the pre-VAT fare.
        foreach ($out->payments()->whereIn('status', ['pending', 'link_sent'])->get() as $p) {
            $this->assertEqualsWithDelta(138.0, (float) $p->amount, 0.01);
        }

        $html = (new \App\Mail\BookingConfirmationMail($out->fresh(), paid: false, payUrl: '#'))->render();
        $this->assertStringContainsString('£318.00', $html);
        $this->assertStringContainsString('Includes VAT £53.00 (net £265.00)', $html);
    }

    public function test_the_vat_toggle_switches_both_legs_of_a_return(): void
    {
        Mail::fake();
        $this->actingAs($admin = User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['quoted_price' => 110, 'leg_price_out' => 55, 'leg_price_return' => 55]));
        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();

        $this->actingAs($admin)->post(route('bookings.vat-invoice', $ret), ['vat' => '1']);
        $this->assertTrue($out->fresh()->vatInvoiceRequested());
        $this->assertTrue($ret->fresh()->vatInvoiceRequested());

        $this->actingAs($admin)->post(route('bookings.vat-invoice', $out), ['vat' => '0']);
        $this->assertFalse($out->fresh()->vatInvoiceRequested());
        $this->assertFalse($ret->fresh()->vatInvoiceRequested());
    }
}
