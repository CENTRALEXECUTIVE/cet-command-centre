<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use App\Services\Payments\PaymentGateway;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Every way a customer changes a return trip (outbound …A + return …B): what the
 * ONE email they get shows, and what the pay link charges. Modelled on the
 * JELD-WEN trip (MAN T2 → office £115, office → Ibis Birmingham £150, VAT on top).
 */
class ReturnTripChangesTest extends TestCase
{
    use RefreshDatabase;

    private Booking $out;

    private Booking $ret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-28 14:00', 'Europe/London'));

        $customer = Customer::create(['name' => 'Christian Michel', 'email' => 'jd@example.com', 'phone' => '07345450257']);
        [$o, $r] = Booking::generateReferencePair();
        $exec = VehicleType::where('slug', 'executive')->first();
        $vat = ['vat_invoice_requested' => true, 'billing_entity' => 'transfers'];
        $common = ['customer_id' => $customer->id, 'payment_method' => 'card', 'payment_status' => 'pending',
            'journey_type' => 'return', 'vehicle_type_id' => $exec->id, 'source' => 'phone'];

        $this->out = Booking::factory()->create($common + ['reference' => $o, 'quoted_price' => 115, 'flight_number' => 'IB987',
            'meta' => $vat + ['meet_greet' => true],
            'pickup_at' => Carbon::parse('2026-11-02 13:45', 'Europe/London'),
            'pickup_address' => 'Manchester Airport Terminal 2', 'destination_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ']);
        $this->ret = Booking::factory()->create($common + ['reference' => $r, 'is_return_leg' => true, 'quoted_price' => 150,
            'meta' => $vat, 'pickup_at' => Carbon::parse('2026-11-03 18:00', 'Europe/London'),
            'pickup_address' => 'JELD-WEN, Shepcote Lane, Sheffield S9 1DZ', 'destination_address' => 'Ibis Birmingham Airport, Bickenhill B26 3AW']);
        $this->out->forceFill(['linked_booking_id' => $this->ret->id])->save();
        $this->ret->forceFill(['linked_booking_id' => $this->out->id])->save();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Edit a leg the way the booking form does (stamps last_edited_at). */
    private function edit(Booking $leg, array $attrs): void
    {
        $leg = $leg->fresh();
        $leg->forceFill($attrs + ['meta' => array_merge($leg->meta ?? [], ['last_edited_at' => now()->addMinute()->toIso8601String()])])->save();
    }

    private function sentHtml(): string
    {
        return Mail::sent(BookingConfirmationMail::class)->last()->render();
    }

    private function payLinkCharges(Booking $leg): float
    {
        $charged = null;
        $gateway = \Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createCheckoutUrl')->andReturnUsing(function ($b, $amount) use (&$charged) {
            $charged = $amount;

            return 'https://checkout.example/x';
        });
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->get(URL::signedRoute('widget.pay', ['booking' => $leg->id]));

        return (float) $charged;
    }

    public function test_both_references_share_one_base_and_differ_only_by_a_or_b(): void
    {
        $this->assertSame(substr($this->out->reference, 0, -1), substr($this->ret->reference, 0, -1));
        $this->assertStringEndsWith('A', $this->out->reference);
        $this->assertStringEndsWith('B', $this->ret->reference);

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $this->out));
        $this->assertStringContainsString($this->out->reference.' / '.$this->ret->reference, $this->sentHtml());
    }

    public function test_changing_the_outbound_time_marks_only_the_outbound_updated(): void
    {
        $this->out->forceFill(['meta' => array_merge($this->out->meta, ['customer_email_sent' => now()->toIso8601String()])])->save();
        $this->ret->forceFill(['meta' => array_merge($this->ret->meta, ['customer_email_sent' => now()->toIso8601String()])])->save();
        $this->edit($this->out, ['pickup_at' => Carbon::parse('2026-11-02 14:30', 'Europe/London')]);

        $this->actingAs($this->admin())->post(route('bookings.updated-email', $this->out))->assertSessionHas('status');

        $html = $this->sentHtml();
        $this->assertStringContainsString('02/11/2026 14:30', $html);
        $this->assertSame(1, substr_count($html, '>UPDATED<'));            // outbound only
        $this->assertStringContainsString('£318.00', $html);               // total unchanged
        $this->assertSame(1, Mail::sent(BookingConfirmationMail::class)->count());
    }

    public function test_sending_from_the_return_leg_page_reads_the_same_outbound_first(): void
    {
        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $this->ret));

        $html = $this->sentHtml();
        $this->assertLessThan(strpos($html, 'Return journey'), strpos($html, 'Outbound journey'));
        $this->assertStringContainsString('£318.00', $html);
    }

    public function test_a_return_address_change_after_paying_charges_only_the_difference(): void
    {
        // Whole trip £318 paid on the outbound's link, both legs settled.
        $this->out->fresh()->markFarePaid('pi_trip', 318, 'transfers');
        app(BookingStatusService::class)->onFarePaid($this->out->fresh());
        $this->assertSame(0.0, round((float) $this->ret->fresh()->transactionsAmountDue(), 2));

        // Customer changes the return drop-off: £150 → £170 net (+£24 incl. VAT).
        $this->actingAs($this->admin())->post(route('bookings.price', $this->ret), ['final_price' => 170]);

        $this->assertEqualsWithDelta(24.0, $this->payLinkCharges($this->out->fresh()), 0.01);

        $this->actingAs($this->admin())->post(route('bookings.updated-email', $this->ret));
        $html = $this->sentHtml();
        $this->assertStringContainsString('Total (whole trip):', $html);
        $this->assertStringContainsString('£342.00', $html);   // (115 + 170) × 1.2
        $this->assertStringContainsString('Complete payment — £24.00', $html);
    }

    public function test_a_time_change_on_a_paid_trip_says_paid_in_full_with_no_pay_button(): void
    {
        $this->out->fresh()->markFarePaid('pi_trip', 318, 'transfers');
        app(BookingStatusService::class)->onFarePaid($this->out->fresh());
        Mail::fake();
        $this->edit($this->ret, ['pickup_at' => Carbon::parse('2026-11-03 19:00', 'Europe/London')]);

        $this->actingAs($this->admin())->post(route('bookings.updated-email', $this->ret));

        $html = $this->sentHtml();
        $this->assertStringContainsString('Paid in full', $html);
        $this->assertStringNotContainsString('Complete payment', $html);
        $this->assertStringContainsString('03/11/2026 19:00', $html);
    }

    public function test_cancelling_the_return_before_paying_drops_it_from_the_total_and_the_charge(): void
    {
        $this->actingAs($this->admin())->post(route('bookings.cancel', $this->ret), ['cancellation_reason' => 'Customer no longer needs the return']);

        $this->assertEqualsWithDelta(138.0, $this->payLinkCharges($this->out->fresh()), 0.01); // outbound only incl. VAT

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $this->out));
        $html = $this->sentHtml();
        $this->assertStringContainsString('Cancelled:', $html);
        $this->assertStringNotContainsString('£318.00', $html);
        $this->assertStringContainsString('£138.00', $html);
    }

    public function test_paying_the_trip_sends_one_paid_confirmation_not_one_per_leg(): void
    {
        $this->out->fresh()->markFarePaid('pi_trip', 318, 'transfers');
        app(BookingStatusService::class)->onFarePaid($this->out->fresh());

        $this->assertSame('paid', $this->out->fresh()->payment_status);
        $this->assertSame('paid', $this->ret->fresh()->payment_status);
        $this->assertSame(1, Mail::sent(BookingConfirmationMail::class)->count());
        $this->assertStringContainsString('Paid in full', $this->sentHtml());
    }

    public function test_changing_the_return_vehicle_shows_on_the_email(): void
    {
        $vclass = VehicleType::where('slug', 'v-class')->first();
        $this->edit($this->ret, ['vehicle_type_id' => $vclass->id, 'quoted_price' => 205]);

        $this->actingAs($this->admin())->post(route('bookings.updated-email', $this->out));

        $html = $this->sentHtml();
        $this->assertStringContainsString('V Class', $html);
        $this->assertStringContainsString('£384.00', $html); // (115 + 205) × 1.2
    }

    public function test_adding_a_return_later_pairs_the_reference_and_is_not_marked_paid(): void
    {
        // A paid one-way (reference …A) — the customer asks to add a return.
        $one = Booking::factory()->create([
            'reference' => 'CET-ABCDEA', 'customer_id' => $this->out->customer_id, 'payment_method' => 'card',
            'payment_status' => 'paid', 'quoted_price' => 105, 'pickup_at' => now()->addDays(3),
            'pickup_address' => 'Sheffield S1 2BP', 'destination_address' => 'Manchester Airport Terminal 1',
            'meta' => ['meet_greet' => true, 'customer_email_sent' => now()->toIso8601String(), 'square_payment' => ['id' => 'pi_one', 'amount' => 105]],
        ]);

        $this->actingAs($this->admin())->post(route('bookings.return-leg', $one), [
            'return_pickup_at' => now()->addDays(6)->format('Y-m-d\TH:i'), 'flight_number' => 'EZY101',
        ])->assertRedirect();

        $ret = Booking::where('linked_booking_id', $one->id)->firstOrFail();
        $this->assertSame('CET-ABCDEB', $ret->reference);
        $this->assertSame('pending', $ret->payment_status);
        $this->assertEmpty($ret->meta['square_payment'] ?? null);
        $this->assertNotEmpty($ret->meta['meet_greet'] ?? null); // return collects from the airport
    }

    private function payTrip(): void
    {
        $this->out->fresh()->markFarePaid('pi_trip', 318, 'transfers');
        app(BookingStatusService::class)->onFarePaid($this->out->fresh());
        Mail::fake();
    }

    public function test_cancelling_only_the_paid_return_refunds_just_the_return(): void
    {
        $this->payTrip();
        $this->actingAs($this->admin())->post(route('bookings.cancel', $this->ret), ['cancellation_reason' => 'Return not needed']);
        $this->actingAs($this->admin())->post(route('bookings.cancellation-email.send', $this->ret))->assertSessionHas('status');

        Mail::assertSent(\App\Mail\BookingCancellationMail::class, 1);
        $html = Mail::sent(\App\Mail\BookingCancellationMail::class)->first()->render();
        $this->assertStringContainsString('Refund of £180.00', $html);          // the return incl. VAT — not £318
        $this->assertStringNotContainsString('£318', $html);
        $this->assertStringContainsString('Still booked', $html);               // the outbound stands
        $this->assertStringContainsString($this->out->reference, $html);
    }

    public function test_cancelling_the_whole_trip_cancels_both_and_sends_one_email(): void
    {
        $this->payTrip();
        $this->actingAs($this->admin())->post(route('bookings.cancel', $this->out), ['cancellation_reason' => 'Trip off', 'cancel_trip' => '1']);

        $this->assertSame('cancelled', $this->out->fresh()->status->value);
        $this->assertSame('cancelled', $this->ret->fresh()->status->value);

        $this->actingAs($this->admin())->post(route('bookings.cancellation-email.send', $this->out));
        $this->assertNotEmpty($this->ret->fresh()->meta['cancellation_email_sent'] ?? null); // covered by the one email

        $mail = Mail::sent(\App\Mail\BookingCancellationMail::class)->first();
        $html = $mail->render();
        $this->assertSame(1, Mail::sent(\App\Mail\BookingCancellationMail::class)->count());
        $this->assertStringContainsString('Refund of £318.00', $html);
        $this->assertStringContainsString('Cancelled return journey', $html);
        $this->assertStringContainsString($this->out->reference.' / '.$this->ret->reference, $mail->envelope()->subject);
    }

    public function test_a_kept_cancellation_fee_is_taken_off_the_refund(): void
    {
        $this->payTrip();
        $this->actingAs($this->admin())->post(route('bookings.cancel', $this->ret), ['cancellation_reason' => 'Late cancel']);
        $r = $this->ret->fresh();
        $r->forceFill(['meta' => array_merge($r->meta, ['cancellation' => ['fee' => 50, 'original_fare' => 150]])])->save();

        $html = (new \App\Mail\BookingCancellationMail($r->fresh()))->render();
        $this->assertStringContainsString('Refund of £130.00', $html); // £180 paid − £50 kept
    }

    public function test_the_cancel_form_offers_to_cancel_the_whole_trip(): void
    {
        $this->actingAs($this->admin())->get(route('bookings.show', $this->out))->assertOk()
            ->assertSee('name="cancel_trip"', false)
            ->assertSee('Also cancel the return '.$this->ret->reference, false);
    }
}
