<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The card-payment safety net: a customer pays through the pay link but the
 * webhook never reaches us (CET-CB4218, Oct 2026 — paid £360, booking stayed
 * Pending, nobody was emailed). The reconcile run asks the provider directly,
 * records the payment and sends both paid emails — once.
 */
class ReconcilePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function unpaidWebBooking(string $ref = 'CET-CB4218'): Booking
    {
        $customer = Customer::create(['name' => 'Amie Frempong', 'email' => 'amie@example.com', 'phone' => '07903422594']);

        return Booking::factory()->create([
            'reference' => $ref, 'customer_id' => $customer->id, 'source' => 'web',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 360,
            'pickup_at' => now()->addDays(2),
            'meta' => ['web_booking' => true, 'billing_entity' => 'chauffeurs'],
        ]);
    }

    public function test_a_stripe_payment_the_webhook_missed_is_recorded_and_emailed(): void
    {
        Mail::fake();
        config(['services.stripe.secret_key' => 'sk_test_123']);
        $booking = $this->unpaidWebBooking();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions*' => Http::response(['has_more' => false, 'data' => [[
                'id' => 'cs_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_360',
                'client_reference_id' => 'FARE-CET-CB4218', 'amount_total' => 36000,
            ]]], 200),
        ]);

        $this->artisan('cet:reconcile-payments')->assertSuccessful();

        $booking = $booking->fresh();
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame(360.0, $booking->onlinePaidTotal());
        Mail::assertSent(BookingConfirmationMail::class);
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid');

        // Running again (or the late webhook arriving) changes nothing and sends nothing more.
        $sent = Mail::sent(BookingConfirmationMail::class)->count();
        $this->artisan('cet:reconcile-payments')->assertSuccessful();
        $this->assertSame($sent, Mail::sent(BookingConfirmationMail::class)->count());
        $this->assertSame(360.0, $booking->fresh()->onlinePaidTotal());
    }

    public function test_unpaid_test_and_unknown_sessions_are_ignored(): void
    {
        Mail::fake();
        config(['services.stripe.secret_key' => 'sk_test_123']);
        $booking = $this->unpaidWebBooking();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions*' => Http::response(['has_more' => false, 'data' => [
                ['id' => 'cs_a', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_a', 'client_reference_id' => 'FARE-CET-CB4218', 'amount_total' => 36000],
                ['id' => 'cs_b', 'payment_status' => 'paid', 'payment_intent' => 'pi_b', 'client_reference_id' => 'TEST-ABC', 'amount_total' => 100],
                ['id' => 'cs_c', 'payment_status' => 'paid', 'payment_intent' => 'pi_c', 'client_reference_id' => 'FARE-CET-NOPE', 'amount_total' => 5000],
            ]], 200),
        ]);

        $this->artisan('cet:reconcile-payments')->assertSuccessful();

        $this->assertSame('pending', $booking->fresh()->payment_status);
        Mail::assertNothingSent();
    }

    public function test_a_square_payment_the_webhook_missed_is_recorded(): void
    {
        Mail::fake();
        config([
            'services.square.access_token' => 'sq_token', 'services.square.location_id' => 'LOC1',
            'services.square.environment' => 'production',
        ]);
        $booking = $this->unpaidWebBooking('CET-SQ0001');
        $booking->forceFill(['meta' => array_merge($booking->meta, ['billing_entity' => 'transfers'])])->save();

        Http::fake([
            'connect.squareup.com/v2/payments*' => Http::response(['payments' => [[
                'id' => 'sqp_1', 'status' => 'COMPLETED', 'order_id' => 'ord_1',
                'amount_money' => ['amount' => 36000, 'currency' => 'GBP'],
            ]]], 200),
            'connect.squareup.com/v2/orders/ord_1' => Http::response(['order' => ['id' => 'ord_1', 'reference_id' => 'FARE-CET-SQ0001']], 200),
        ]);

        $this->artisan('cet:reconcile-payments')->assertSuccessful();

        $this->assertSame('paid', $booking->fresh()->payment_status);
        Mail::assertSent(BookingConfirmationMail::class);
    }

    public function test_nothing_happens_when_no_provider_is_configured(): void
    {
        Http::fake();
        $this->artisan('cet:reconcile-payments')->assertSuccessful();
        Http::assertNothingSent();
    }
}
