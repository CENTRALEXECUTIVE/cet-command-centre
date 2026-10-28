<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\WatchdogEvent;
use App\Services\BookingStatusService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "Emails should always work": every payment gets its emails, a failed send is
 * retried, a part payment never claims "paid", and the money can't be taken
 * twice or into the wrong company without the office being told.
 */
class PaymentEmailReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cet.ops_email' => 'ops@example.com']);
    }

    private function booking(float $price = 55): Booking
    {
        $customer = Customer::create(['name' => 'John Mannix', 'email' => 'john@example.com', 'phone' => '07700900000']);

        return Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'web', 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => $price, 'pickup_at' => now()->addDays(3),
            'meta' => ['web_booking' => true, 'billing_entity' => 'chauffeurs'],
        ]);
    }

    private function pay(Booking $booking, string $id, float $amount): void
    {
        $booking->fresh()->markFarePaid($id, $amount, 'chauffeurs');
        app(BookingStatusService::class)->onFarePaid($booking->fresh());
    }

    public function test_a_balance_paid_after_a_re_price_gets_its_own_receipt_and_office_email(): void
    {
        Mail::fake();
        $booking = $this->booking(55);
        $this->pay($booking, 'pi_first', 55);
        Mail::assertSent(BookingConfirmationMail::class, 1);
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid');

        $booking->fresh()->forceFill(['final_price' => 100])->save();
        $this->pay($booking, 'pi_balance', 45);

        Mail::assertSent(BookingConfirmationMail::class, 2);
        $this->assertSame(2, Mail::sent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid')->count());

        // A webhook retry of the balance sends nothing more.
        app(BookingStatusService::class)->onFarePaid($booking->fresh());
        Mail::assertSent(BookingConfirmationMail::class, 2);
    }

    public function test_a_part_payment_never_sends_the_customer_a_paid_confirmation(): void
    {
        Mail::fake();
        $booking = $this->booking(150);
        $this->pay($booking, 'pi_deposit', 15);

        Mail::assertNotSent(BookingConfirmationMail::class);
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid');
        $this->assertSame('pending', $booking->fresh()->payment_status);
    }

    public function test_the_reconcile_run_retries_paid_emails_that_did_not_go(): void
    {
        Mail::fake();
        Http::fake();
        $booking = $this->booking(55);
        // Money recorded, but the paid flow never ran (e.g. it errored mid-way).
        $booking->markFarePaid('pi_x', 55, 'chauffeurs');

        $this->artisan('cet:reconcile-payments')->assertSuccessful();

        Mail::assertSent(BookingConfirmationMail::class, 1);
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid');

        // Next run: everything already sent — nothing more goes out.
        $this->artisan('cet:reconcile-payments')->assertSuccessful();
        Mail::assertSent(BookingConfirmationMail::class, 1);
    }

    public function test_an_old_payment_is_never_re_announced_by_the_sweep(): void
    {
        Mail::fake();
        Http::fake();
        $booking = $this->booking(55);
        $booking->forceFill(['payment_status' => 'paid', 'meta' => array_merge($booking->meta, [
            'square_payment' => ['id' => 'pi_old', 'amount' => 55, 'at' => now()->subDays(20)->toIso8601String()],
        ])])->save();

        $this->artisan('cet:reconcile-payments')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_card_payment_confirming_a_hand_mark_paid_sends_nothing_again(): void
    {
        // CET-CB4218, 29 Oct: the office pressed Mark paid (customer + office
        // emailed), then the safety net found the real Stripe payment. That's the
        // SAME money — the customer must not get the confirmation a second time.
        Mail::fake();
        config(['services.stripe.secret_key' => 'sk_test_123']);
        $booking = $this->booking(360);
        $booking->forceFill(['reference' => 'CET-CB4218'])->save();
        $booking->fresh()->markFarePaid('MANUAL-'.$booking->id.'-1', 360, 'chauffeurs');
        app(BookingStatusService::class)->onFarePaid($booking->fresh());
        Mail::assertSent(BookingConfirmationMail::class, 1);
        $officeBefore = Mail::sent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid')->count();

        Http::fake(['api.stripe.com/v1/checkout/sessions*' => Http::response(['has_more' => false, 'data' => [[
            'id' => 'cs_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_360',
            'client_reference_id' => 'FARE-CET-CB4218', 'amount_total' => 36000,
        ]]], 200)]);
        $this->artisan('cet:reconcile-payments')->assertSuccessful();

        Mail::assertSent(BookingConfirmationMail::class, 1);
        $this->assertSame($officeBefore, Mail::sent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid')->count());
        $this->assertSame(360.0, $booking->fresh()->onlinePaidTotal());
        $this->assertFalse(WatchdogEvent::where('event_type', 'possible_double_payment')->exists());
    }

    public function test_the_heal_command_repairs_a_booking_counted_twice(): void
    {
        $booking = $this->booking(360);
        $booking->forceFill(['payment_status' => 'paid', 'meta' => array_merge($booking->meta, [
            'paid_email_sent' => now()->toIso8601String(), 'paid_email_for' => 'MANUAL-1',
            'square_payment' => ['id' => 'pi_360', 'amount' => 720, 'history' => [
                ['id' => 'MANUAL-1', 'amount' => 360, 'at' => now()->toIso8601String()],
                ['id' => 'pi_360', 'amount' => 360, 'at' => now()->toIso8601String()],
            ]],
        ])])->save();
        WatchdogEvent::log('possible_double_payment', 'x', 'warning', $booking);

        $this->artisan('cet:heal-manual-payments')->assertSuccessful();

        $b = $booking->fresh();
        $this->assertSame(360.0, $b->onlinePaidTotal());
        $this->assertSame('pi_360', $b->meta['paid_email_for']);
        $this->assertNotNull(WatchdogEvent::where('event_type', 'possible_double_payment')->first()->acknowledged_at);
    }

    public function test_clicking_pay_twice_reuses_the_same_checkout(): void
    {
        $booking = $this->booking(360);
        $gateway = \Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createCheckoutUrl')->once()->andReturn('https://checkout.example/one');
        $this->app->instance(PaymentGateway::class, $gateway);

        $link = URL::signedRoute('widget.pay', ['booking' => $booking->id]);
        $this->get($link)->assertRedirect('https://checkout.example/one');
        $this->get($link)->assertRedirect('https://checkout.example/one');
    }

    public function test_a_new_amount_after_a_re_price_gets_a_new_checkout(): void
    {
        $booking = $this->booking(100);
        $gateway = \Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createCheckoutUrl')->twice()->andReturn('https://checkout.example/a', 'https://checkout.example/b');
        $this->app->instance(PaymentGateway::class, $gateway);

        $link = URL::signedRoute('widget.pay', ['booking' => $booking->id]);
        $this->get($link)->assertRedirect('https://checkout.example/a');
        $booking->fresh()->forceFill(['final_price' => 120])->save();
        $this->get($link)->assertRedirect('https://checkout.example/b');
    }

    public function test_paying_twice_raises_a_double_payment_alert(): void
    {
        Mail::fake();
        $booking = $this->booking(360);
        $this->pay($booking, 'pi_1', 360);
        $this->pay($booking, 'pi_2', 360);

        $this->assertTrue(WatchdogEvent::where('event_type', 'possible_double_payment')->where('booking_id', $booking->id)->exists());
    }

    public function test_a_pvt_booking_paid_into_the_ltd_square_account_raises_an_alert(): void
    {
        config([
            'services.stripe.secret_key' => null,
            'services.square.access_token' => 'sq', 'services.square.location_id' => 'LOC',
        ]);
        Http::fake(['*' => Http::response(['payment_link' => ['url' => 'https://square.link/x']], 200)]);
        $booking = $this->booking(100);

        app(PaymentGateway::class)->createCheckoutUrl($booking, 100.0);

        $this->assertTrue(WatchdogEvent::where('event_type', 'payment_entity_fallback')->where('booking_id', $booking->id)->exists());
    }
}
