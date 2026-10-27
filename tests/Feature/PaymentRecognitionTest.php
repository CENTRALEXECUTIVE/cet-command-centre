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

        // The £55 already paid is preserved; only the £45 balance is now due.
        $this->assertSame(55.0, $booking->transactionsPaidTotal());
        $this->assertSame(45.0, $booking->transactionsAmountDue());
        $this->assertTrue($booking->fareIsPaid());
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
