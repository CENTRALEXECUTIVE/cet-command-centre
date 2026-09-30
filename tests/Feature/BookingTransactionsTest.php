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
