<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A return trip is one payment for the whole journey: the pay link charges the
 * TOTAL of both legs, and paying once marks both legs paid (each for its own
 * fare), so the combined invoice reads "Paid in full".
 */
class ReturnTripPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Booking, 1: Booking} [outbound, return] */
    private function returnPair(): array
    {
        $customer = Customer::create(['name' => 'Pauric Shanley', 'email' => 'p@example.com', 'phone' => '00353860889895']);
        [$outRef, $retRef] = Booking::generateReferencePair();
        $common = ['customer_id' => $customer->id, 'source' => 'web', 'payment_method' => 'card', 'payment_status' => 'pending'];

        $out = Booking::factory()->create($common + ['reference' => $outRef, 'journey_type' => 'return', 'quoted_price' => 115, 'pickup_address' => 'Manchester Airport', 'destination_address' => 'Sheffield S1 4LR']);
        $ret = Booking::factory()->create($common + ['reference' => $retRef, 'journey_type' => 'return', 'is_return_leg' => true, 'quoted_price' => 105, 'pickup_address' => 'Sheffield S1 4LR', 'destination_address' => 'Manchester Airport']);
        foreach ([[$out, $ret], [$ret, $out]] as [$a, $b]) {
            $a->forceFill(['meta' => array_merge($a->meta ?? [], ['billing_entity' => 'chauffeurs']), 'linked_booking_id' => $b->id])->save();
        }

        return [$out->fresh(), $ret->fresh()];
    }

    public function test_paying_one_leg_settles_the_whole_return_trip(): void
    {
        Mail::fake();
        [$out, $ret] = $this->returnPair();

        // The whole trip (£220) is paid on the outbound's checkout; the webhook marks
        // that leg paid, then the paid-flow settles the rest of the trip.
        $out->markFarePaid('pi_trip', 220, 'chauffeurs');
        app(BookingStatusService::class)->onFarePaid($out->fresh());

        // BOTH legs are now paid — each for its own fare.
        $this->assertTrue($out->fresh()->fareIsPaid());
        $this->assertTrue($ret->fresh()->fareIsPaid());
        $this->assertEqualsWithDelta(115.0, $out->fresh()->transactionsPaidTotal(), 0.01);
        $this->assertEqualsWithDelta(105.0, $ret->fresh()->transactionsPaidTotal(), 0.01);
        // The combined invoice owes nothing.
        $this->assertEqualsWithDelta(0.0, (float) $out->fresh()->transactionsAmountDue(), 0.01);
    }

    public function test_paying_one_leg_on_its_own_does_NOT_mark_the_other_paid(): void
    {
        // The failsafe: the outbound is paid for ITS OWN fare (£115), not the whole
        // trip — so the unpaid return (£105) must stay unpaid. (Previously this wrongly
        // marked the return paid and emailed the customer.)
        \Illuminate\Support\Facades\Mail::fake();
        [$out, $ret] = $this->returnPair();

        $out->markFarePaid('pi_out_only', 115, 'chauffeurs'); // just this leg's fare
        app(BookingStatusService::class)->onFarePaid($out->fresh());

        $this->assertTrue($out->fresh()->fareIsPaid());
        $this->assertFalse($ret->fresh()->fareIsPaid(), 'the return must NOT be settled by a single-leg payment');
        $this->assertSame('pending', $ret->fresh()->payment_status);
    }

    public function test_the_pay_link_charges_the_whole_trip_total(): void
    {
        config(['services.stripe.secret_key' => 'sk_test_123']);
        Http::fake([
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1'], 200),
            'api.stripe.com/v1/payment_links' => Http::response(['id' => 'pl_1', 'url' => 'https://buy.stripe.com/x'], 200),
        ]);
        [$out, $ret] = $this->returnPair();

        // Click the RETURN leg's pay link → it charges the whole trip, £220.
        $url = URL::signedRoute('widget.pay', ['booking' => $ret->id]);
        $this->get($url)->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/prices')
            && str_contains(urldecode(http_build_query($r->data())), 'unit_amount=22000'));
    }
}
