<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A return booked for an account client (JELD-WEN, Oct 2026): the one email the
 * office sends must show BOTH journeys and the whole-trip money incl. VAT — not
 * just the outbound fare beside a button that charges the whole trip.
 */
class ReturnTripConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_confirmation_lists_both_journeys_and_the_trip_total_with_vat(): void
    {
        $customer = Customer::create(['name' => 'Christian Michel', 'email' => 'jd@example.com', 'phone' => '07345450257']);
        [$outRef, $retRef] = Booking::generateReferencePair();
        $common = ['customer_id' => $customer->id, 'payment_method' => 'card', 'payment_status' => 'pending', 'journey_type' => 'return'];
        $vat = ['vat_invoice_requested' => true, 'billing_entity' => 'transfers'];

        $out = Booking::factory()->create($common + [
            'reference' => $outRef, 'quoted_price' => 115, 'meta' => $vat + ['meet_greet' => true],
            'pickup_at' => Carbon::parse('2026-11-02 13:45', 'Europe/London'),
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2', 'destination_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ',
        ]);
        $ret = Booking::factory()->create($common + [
            'reference' => $retRef, 'is_return_leg' => true, 'quoted_price' => 150, 'meta' => $vat + ['meet_greet' => true],
            'pickup_at' => Carbon::parse('2026-11-04 18:00', 'Europe/London'),
            'pickup_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ', 'destination_address' => 'Ibis Birmingham Airport, Bickenhill B26 3AW',
        ]);
        foreach ([[$out, $ret], [$ret, $out]] as [$a, $b]) {
            $a->forceFill(['linked_booking_id' => $b->id])->save();
        }

        $html = (new BookingConfirmationMail($out->fresh(), paid: false, payUrl: 'https://pay.example/x'))->render();

        $this->assertStringContainsString('Return journey', $html);
        $this->assertStringContainsString('Ibis Birmingham Airport', $html);
        $this->assertStringContainsString('Total (whole trip):', $html);
        $this->assertStringContainsString('£318.00', $html);        // (115 + 150) + 20% VAT
        $this->assertStringContainsString('Includes VAT £53.00 (net £265.00)', $html);
        $this->assertStringContainsString('Complete payment — £318.00', $html);
        // No meet & greet promised at the office pickup.
        $this->assertSame(1, substr_count($html, 'driver waits in arrivals'));
    }
}
