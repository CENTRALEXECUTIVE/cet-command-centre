<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ETO-style per-booking payment ledger ("Payment history"): add, edit, mark paid,
 * duplicate and delete transactions; the booking's headline paid/pending flag
 * follows the ledger.
 */
class BookingTransactionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(): Booking
    {
        $exec = VehicleType::where('slug', 'executive')->first();

        return Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 105.00,
            'payment_status' => 'pending',
        ]);
    }

    public function test_a_full_length_stripe_checkout_url_fits_the_payment_link_column(): void
    {
        // A live Stripe Checkout URL is 500+ chars (a long cs_live_…#fid… fragment).
        // The tide_payment_link column must hold it, not 500 with "Data too long".
        $booking = $this->booking();
        $longUrl = 'https://checkout.stripe.com/c/pay/cs_live_'.str_repeat('aA1bB2cC3', 90); // ~850 chars
        $this->assertGreaterThan(500, strlen($longUrl));

        $payment = $booking->payments()->create([
            'method' => 'card', 'amount' => 150, 'status' => 'link_sent',
            'tide_payment_link' => $longUrl,
        ]);

        $this->assertSame($longUrl, $payment->fresh()->tide_payment_link);
    }

    public function test_an_admin_can_add_a_transaction(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();

        $this->actingAs($admin)->post(route('bookings.transactions.store', $booking), [
            'name' => 'Full amount', 'amount' => 105, 'method' => 'card', 'status' => 'pending',
        ])->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id, 'amount' => 105.00, 'method' => 'card', 'status' => 'pending',
        ]);
    }

    public function test_marking_the_only_transaction_paid_marks_the_booking_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $tx = $booking->payments()->create(['method' => 'card', 'amount' => 105, 'status' => 'pending']);

        $this->actingAs($admin)->post(route('bookings.transactions.pay-now', [$booking, $tx]))->assertRedirect();

        $this->assertSame('paid', $tx->fresh()->status);
        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_a_part_payment_leaves_the_booking_pending_with_the_balance_due(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking(); // £105 fare
        $deposit = $booking->payments()->create(['method' => 'card', 'amount' => 25, 'status' => 'paid', 'paid_at' => now()]);

        // Reconcile via a status change on the deposit.
        $this->actingAs($admin)->post(route('bookings.transactions.status', [$booking, $deposit]), ['status' => 'paid'])->assertRedirect();

        $booking->refresh()->load('payments');
        $this->assertSame('pending', $booking->payment_status);
        $this->assertEqualsWithDelta(80.0, $booking->transactionsAmountDue(), 0.001);
    }

    public function test_paying_the_fare_online_marks_the_open_card_transaction_paid(): void
    {
        // The office sends a "Pay now" card link (a link_sent ledger row). The
        // customer pays it → the webhook records the fare. The ledger row must flip
        // to Paid, not sit reading "Pending".
        $booking = $this->booking(); // £105 fare
        $tx = $booking->payments()->create([
            'method' => 'card', 'amount' => 105, 'status' => 'link_sent',
            'meta' => ['name' => 'Pay now'],
        ]);

        // Webhook records the online payment.
        $booking->markFarePaid('sq_paid_1', 105.0);

        $this->assertSame('paid', $tx->fresh()->status);
        $this->assertNotNull($tx->fresh()->paid_at);
        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_a_cash_ledger_row_is_not_marked_paid_by_an_online_fare_payment(): void
    {
        // Only the online CARD payment settles online — a separate cash row (what
        // the driver collects) must be left alone.
        $booking = $this->booking();
        $cash = $booking->payments()->create(['method' => 'cash', 'amount' => 40, 'status' => 'pending']);

        $booking->markFarePaid('sq_paid_2', 105.0);

        $this->assertSame('pending', $cash->fresh()->status);
    }

    public function test_a_recorded_paid_deposit_reduces_the_online_balance_to_the_remainder(): void
    {
        // Office books a job in and takes a £30 deposit via the simple "Deposit
        // taken (paid)" field (no ledger rows). The pay-now / balance link must
        // then charge only the £75 remainder, never the whole £105 again.
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 105.00,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'meta' => ['deposit' => ['amount' => 30, 'paid' => true]],
        ]);

        $this->assertEqualsWithDelta(105.0, $booking->amountPayable(), 0.001);
        $this->assertEqualsWithDelta(75.0, $booking->transactionsAmountDue(), 0.001);
    }

    public function test_an_admin_can_duplicate_and_delete_a_transaction(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $tx = $booking->payments()->create(['method' => 'cash', 'amount' => 50, 'status' => 'pending']);

        $this->actingAs($admin)->post(route('bookings.transactions.duplicate', [$booking, $tx]))->assertRedirect();
        $this->assertSame(2, $booking->payments()->count());

        $this->actingAs($admin)->delete(route('bookings.transactions.destroy', [$booking, $tx]))->assertRedirect();
        $this->assertSame(1, $booking->fresh()->payments()->count());
    }

    public function test_a_square_paid_booking_with_no_ledger_rows_reads_as_paid(): void
    {
        // Paid online via Square (recorded on the booking, not as a ledger row) —
        // the Payment history must show it paid, not £0 / amount due.
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 115.00,
            'payment_status' => 'paid',
            'meta' => ['square_payment' => ['id' => 'sq_abc', 'amount' => 115]],
        ]);

        $this->assertTrue($booking->fareIsPaid());
        $this->assertEqualsWithDelta(115.0, $booking->transactionsPaidTotal(), 0.001);
        $this->assertEqualsWithDelta(0.0, $booking->transactionsAmountDue(), 0.001);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Full amount')->assertDontSee('No transactions recorded yet');
    }

    public function test_a_soiling_charge_on_a_paid_imported_booking_keeps_the_fare_paid(): void
    {
        // CET-583A55: an ETO booking flagged paid (no online record) got a £150
        // soiling line as its first ledger row — the £115 journey then read
        // "£0 paid, £115 due" and a £115 balance link was created.
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 115.00,
            'payment_status' => 'paid',
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.transactions.store', $booking), [
            'amount' => 150, 'method' => 'card', 'status' => 'pending', 'name' => 'Soiling charge',
        ])->assertRedirect();

        $booking->refresh()->load('payments');
        $this->assertSame(2, $booking->payments->count());
        $this->assertEqualsWithDelta(115.0, $booking->transactionsPaidTotal(), 0.001);
        // Only the soiling charge is owed — never the journey again.
        $this->assertEqualsWithDelta(150.0, $booking->transactionsAmountDue(), 0.001);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame('Fare (paid before)', $booking->payments->firstWhere('status', 'paid')->name());
    }

    public function test_an_extra_charge_is_owed_on_top_of_a_paid_journey(): void
    {
        // CET-583A55: £115 journey paid, £150 soiling charge outstanding → £150 due,
        // the journey stays paid, and paying the charge never "pays" the journey.
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 115.00, 'payment_status' => 'paid',
        ]);
        $booking->payments()->create(['method' => 'card', 'amount' => 115, 'status' => 'paid', 'paid_at' => now(), 'meta' => ['name' => 'Full amount']]);
        $soil = $booking->payments()->create(['method' => 'card', 'amount' => 150, 'status' => 'pending', 'meta' => ['name' => 'Soiling charge']]);
        $booking->load('payments');

        $this->assertEqualsWithDelta(150.0, $booking->extraChargesTotal(), 0.001);
        $this->assertEqualsWithDelta(150.0, $booking->transactionsAmountDue(), 0.001);
        $booking->reconcilePaymentStatusFromTransactions();
        $this->assertSame('paid', $booking->fresh()->payment_status);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('£265.00')->assertSee('£150.00 in extra charges is due', false)
            ->assertDontSee('Email payment request');

        $soil->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $booking->load('payments');
        $this->assertEqualsWithDelta(0.0, $booking->transactionsAmountDue(), 0.001);

        // A soiling charge PAID on an UNPAID journey never marks the journey paid.
        $unpaid = Booking::factory()->forVehicleType($exec)->create(['quoted_price' => 115.00, 'payment_status' => 'pending']);
        $unpaid->payments()->create(['method' => 'card', 'amount' => 150, 'status' => 'paid', 'paid_at' => now(), 'meta' => ['name' => 'Soiling charge']]);
        $unpaid->load('payments')->reconcilePaymentStatusFromTransactions();
        $this->assertSame('pending', $unpaid->fresh()->payment_status);
    }

    public function test_an_imported_customer_is_not_emailed_a_payment_request_unless_confirmed(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = \App\Models\Customer::create(['name' => 'Andrea L', 'email' => 'andrea@example.com', 'phone' => '07700900111']);
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 115.00, 'payment_status' => 'pending', 'customer_id' => $customer->id, 'source_system' => 'eto',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.email-payment-request', $booking))->assertRedirect();
        \Illuminate\Support\Facades\Mail::assertNothingSent();

        $this->actingAs($admin)->post(route('bookings.email-payment-request', $booking), ['send_anyway' => 1])->assertRedirect();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BookingConfirmationMail::class);
    }

    public function test_a_driver_cannot_touch_the_ledger(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->booking();

        $this->actingAs($driver)->post(route('bookings.transactions.store', $booking), [
            'amount' => 105, 'method' => 'card', 'status' => 'pending',
        ])->assertForbidden();
    }

    public function test_a_transaction_from_another_booking_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->booking();
        $b = $this->booking();
        $tx = $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'pending']);

        // The payment belongs to $b, not $a → 404.
        $this->actingAs($admin)->post(route('bookings.transactions.pay-now', [$a, $tx]))->assertNotFound();
    }
}
