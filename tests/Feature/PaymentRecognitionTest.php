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
}
