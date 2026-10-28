<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fare that has really been paid (a provider payment is on record) must read
 * as Paid everywhere — the main header must never lag behind a recorded payment.
 */
class PaymentRecognitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function paidButPendingColumn(): Booking
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $b = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 1,
        ]);
        // A real provider payment is recorded, but the status column still says pending.
        $b->forceFill(['meta' => array_merge($b->meta ?? [], [
            'square_payment' => ['id' => 'pi_123', 'amount' => 1.0, 'at' => now()->toIso8601String(), 'entity' => 'chauffeurs'],
        ])])->save();

        return $b->fresh();
    }

    public function test_a_recorded_payment_reads_as_paid_even_if_the_column_lags(): void
    {
        $b = $this->paidButPendingColumn();

        $this->assertTrue($b->fareIsPaid());
        $this->assertStringContainsString('Paid', $b->displayPayment());
    }

    public function test_viewing_the_booking_heals_the_status_column(): void
    {
        $b = $this->paidButPendingColumn();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk();

        $this->assertSame('paid', $b->fresh()->payment_status);
    }

    public function test_a_refunded_booking_is_never_healed_to_paid(): void
    {
        $b = $this->paidButPendingColumn();
        $b->forceFill(['payment_status' => 'refunded'])->save();

        $this->assertFalse($b->reconcilePaidStatus());
        $this->assertSame('refunded', $b->fresh()->payment_status);
    }

    public function test_opening_an_imported_already_paid_booking_never_emails_anyone(): void
    {
        // An ETO/imported booking reads "paid" (payment_status) but has NO captured
        // online payment (no square_payment). Opening it must NEVER retroactively
        // email the customer or the office — that money was taken elsewhere.
        \Illuminate\Support\Facades\Mail::fake();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $customer = \App\Models\Customer::factory()->create(['email' => 'old-customer@example.com']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'vehicle_type_id' => $exec->id,
            'source' => 'phone', 'payment_method' => 'card', 'payment_status' => 'paid',
            'external_reference' => 'S94Y1Ma', 'quoted_price' => 50,
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $this->assertEmpty($booking->fresh()->meta['office_email_paid_sent'] ?? null);
        $this->assertEmpty($booking->fresh()->meta['paid_email_sent'] ?? null);
    }

    public function test_a_genuinely_unpaid_booking_is_not_marked_paid(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $b = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 100,
        ]);

        $this->assertFalse($b->reconcilePaidStatus());
        $this->assertFalse($b->fresh()->fareIsPaid());
    }

    /**
     * Re-pricing a booking after the customer has already paid online must KEEP the
     * amount actually paid and only move the balance — it must never read as if the
     * whole new (higher) price was paid, nor wipe the paid figure to £0. Regression:
     * a £55 Stripe payment, then the office raises the price to £100, must show
     * Paid £55 / Due £45 — not Paid £100 / Due £0, and not Paid £0 / Due £100.
     */
    public function test_re_pricing_a_paid_booking_keeps_the_paid_amount_and_moves_the_balance(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 55,
        ]);

        // Customer pays £55 online (Stripe/Square webhook records it on the booking).
        $booking->markFarePaid('pi_abc', 55.0, 'chauffeurs');
        $booking = $booking->fresh();

        $this->assertSame(55.0, $booking->transactionsPaidTotal());
        $this->assertSame(0.0, $booking->transactionsAmountDue());

        // The office changes the destination and raises the price to £100.
        $booking->forceFill(['final_price' => 100])->save();
        $booking = $booking->fresh();

        // The £55 already paid is preserved; only the £45 balance is now due, and
        // the booking reads part-paid (so the balance can still be charged).
        $this->assertSame(55.0, $booking->transactionsPaidTotal());
        $this->assertSame(45.0, $booking->transactionsAmountDue());
        $this->assertFalse($booking->fareIsPaid());
        $this->assertSame('pending', $booking->payment_status);

        // Viewing the booking never heals it back to "paid".
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk();
        $this->assertSame('pending', $booking->fresh()->payment_status);

        // The customer pays the £45 balance online: it ADDS to the £55.
        $booking->fresh()->markFarePaid('pi_balance', 45.0, 'chauffeurs');
        $booking = $booking->fresh();
        $this->assertSame(100.0, $booking->onlinePaidTotal());
        $this->assertSame(100.0, $booking->transactionsPaidTotal());
        $this->assertSame(0.0, $booking->transactionsAmountDue());
        $this->assertTrue($booking->fareIsPaid());
        $this->assertSame('paid', $booking->payment_status);

        // A webhook retry for the same payment is ignored (no double count).
        $this->assertFalse($booking->markFarePaid('pi_balance', 45.0, 'chauffeurs'));
        $this->assertSame(100.0, $booking->fresh()->onlinePaidTotal());
    }

    public function test_the_stable_pay_link_charges_only_the_balance_after_a_re_price(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 55,
        ]);
        $booking->markFarePaid('pi_abc', 55.0, 'chauffeurs');
        $booking->fresh()->forceFill(['final_price' => 100])->save();

        $gateway = \Mockery::mock(\App\Services\Payments\PaymentGateway::class);
        $gateway->shouldReceive('createCheckoutUrl')->once()
            ->withArgs(fn ($b, $amount) => $b->is($booking) && abs($amount - 45.0) < 0.001)
            ->andReturn('https://checkout.example/pay');
        $this->app->instance(\App\Services\Payments\PaymentGateway::class, $gateway);

        $this->get(\Illuminate\Support\Facades\URL::signedRoute('widget.pay', ['booking' => $booking->id]))
            ->assertRedirect('https://checkout.example/pay');
    }

    public function test_a_balance_line_on_an_online_paid_booking_keeps_the_online_payment_in_the_ledger(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 55,
        ]);
        $booking->markFarePaid('pi_abc', 55.0, 'chauffeurs');
        $booking->fresh()->forceFill(['final_price' => 100])->save();

        // The office adds a £45 card balance line (link sent, not yet paid).
        $booking->payments()->create(['method' => 'card', 'amount' => 45, 'status' => 'link_sent', 'meta' => ['name' => 'Balance']]);
        $booking = $booking->fresh();

        // The £55 online payment was put in the ledger first, so nothing is lost.
        $this->assertCount(2, $booking->payments);
        $this->assertSame(55.0, $booking->transactionsPaidTotal());
        $this->assertSame(45.0, $booking->transactionsAmountDue());

        // Opening the page must NOT tick the open £45 line paid — no money yet.
        $booking->reconcileLedgerFromFarePaid();
        $this->assertSame(45.0, $booking->fresh()->transactionsAmountDue());

        // The balance is paid online → the £45 line is marked paid; £100 in total.
        $booking->markFarePaid('pi_balance', 45.0, 'chauffeurs');
        $booking = $booking->fresh();
        $this->assertSame(100.0, $booking->transactionsPaidTotal());
        $this->assertSame(0.0, $booking->transactionsAmountDue());
        $this->assertSame('paid', $booking->payment_status);
    }

    /**
     * A job simply flagged paid by hand (no recorded online amount) still reads as
     * fully paid against its current fare — the fallback must not break that case.
     */
    public function test_a_hand_marked_paid_job_with_no_recorded_amount_reads_fully_paid(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'card',
            'payment_status' => 'paid', 'quoted_price' => 80,
        ]);

        $this->assertSame(80.0, $booking->transactionsPaidTotal());
        $this->assertSame(0.0, $booking->transactionsAmountDue());
    }
}
