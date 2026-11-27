<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 27 Nov 2026 (CET-14053A/B): the office "Payment received" email showed £138 (inc. VAT)
 * per journey, and the customer's paid confirmation said "Payment received: £138" for a
 * £264 return. Journeys show ex-VAT, then subtotal + VAT = total; the payment is the total.
 */
class TripPaymentEmailFiguresTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_return_paid_on_the_return_leg_shows_the_right_figures(): void
    {
        Mail::fake();
        config(['cet.ops_email' => 'office@example.com']);
        $c = Customer::create(['name' => 'Bill Example', 'email' => 'claire@example.com', 'phone' => '07700900777']);
        [$o, $r] = Booking::generateReferencePair();
        $vat = ['vat_invoice_requested' => true, 'billing_entity' => 'transfers'];
        $common = ['customer_id' => $c->id, 'payment_method' => 'card', 'payment_status' => 'pending', 'journey_type' => 'return', 'source' => 'web',
            'status' => \App\Enums\BookingStatus::Pending->value];
        $out = Booking::factory()->create($common + ['reference' => $o, 'quoted_price' => 115, 'meta' => $vat, 'pickup_at' => now()->addDays(10)]);
        $ret = Booking::factory()->create($common + ['reference' => $r, 'is_return_leg' => true, 'quoted_price' => 105, 'meta' => $vat, 'pickup_at' => now()->addDays(11)]);
        $out->forceFill(['linked_booking_id' => $ret->id])->save();
        $ret->forceFill(['linked_booking_id' => $out->id])->save();

        $ret->fresh()->markFarePaid('sq_claire', 264.0, 'transfers');
        app(BookingStatusService::class)->onFarePaid($ret->fresh());

        $office = (new OfficeBookingMail($out->fresh(), 'paid'))->render();
        foreach (['Fare (excl. VAT)', '£115.00', '£105.00', 'Subtotal (excl. VAT)', '£220.00', 'VAT (20%)', '£44.00', '£264.00'] as $want) {
            $this->assertStringContainsString($want, $office);
        }
        $this->assertStringNotContainsString('£138.00', $office);
        $this->assertStringNotContainsString('£126.00', $office);

        foreach ([$out, $ret] as $leg) {
            $customer = (new BookingConfirmationMail($leg->fresh(), paid: true))->render();
            $this->assertStringContainsString('Payment received: £264.00 by card', $customer);
            $this->assertStringNotContainsString('Payment received: £138.00', $customer);
        }
    }
}
