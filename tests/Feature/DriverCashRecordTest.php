<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment tracking across deposit + cash-to-driver + card, and the email breakdown.
 * Mirrors the real case: £115 fare, £10 deposit (card) + £15 card balance + £90 cash
 * the driver actually collected = paid in full, nothing left untracked.
 */
class DriverCashRecordTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        $this->seed([VehicleTypeSeeder::class, AirportSeeder::class, DirectorSeeder::class]);
        $exec = VehicleType::where('affects_rotation', true)->firstOrFail();

        return Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Yasmine Clarke', 'email' => 'yas@example.com', 'phone' => '07700900999'])->id,
            'vehicle_type_id' => $exec->id,
            'source' => 'web',
            'status' => BookingStatus::Pending->value,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'quoted_price' => 115,
            'pickup_address' => '33 Goose Lane, Rotherham, S66 1JN',
            'destination_address' => 'Manchester Airport M90 1QX',
        ]);
    }

    public function test_deposit_cash_and_card_all_tracked_to_paid_in_full(): void
    {
        $booking = $this->booking();
        $booking->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'paid', 'paid_at' => now(), 'meta' => ['name' => 'Deposit']]);
        $booking->payments()->create(['method' => 'card', 'amount' => 15, 'status' => 'paid', 'paid_at' => now(), 'meta' => ['name' => 'Balance']]);
        $booking->load('payments');

        // Driver records the £90 cash actually collected.
        $tx = $booking->recordCashCollected(90);
        $this->assertNotNull($tx);
        $this->assertSame('cash', $tx->method);

        $booking->refresh()->load('payments');
        $this->assertSame(115.0, round($booking->transactionsPaidTotal(), 2));
        $this->assertSame(0.0, round($booking->transactionsAmountDue() ?? -1, 2));
        $this->assertSame(90.0, $booking->cashCollectedTotal());
        $this->assertSame('paid', $booking->payment_status);
    }

    public function test_a_partial_cash_payment_leaves_the_right_balance(): void
    {
        $booking = $this->booking();
        // £105 balance due; driver only takes £90.
        $booking->recordCashCollected(90);
        $booking->refresh()->load('payments');
        $this->assertSame(90.0, round($booking->transactionsPaidTotal(), 2));
        $this->assertSame(25.0, round($booking->transactionsAmountDue() ?? -1, 2)); // £115 − £90
    }

    public function test_the_paid_email_shows_the_amount_paid_and_breakdown(): void
    {
        $booking = $this->booking();
        $booking->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'paid', 'paid_at' => now()->subDays(3), 'meta' => ['name' => 'Deposit']]);
        $booking->recordCashCollected(90); // cash on the day
        $booking->payments()->create(['method' => 'card', 'amount' => 15, 'status' => 'paid', 'paid_at' => now()->addMinutes(2), 'meta' => ['name' => 'Balance']]); // card link, the latest
        $booking->refresh()->load('payments');

        $html = (new BookingConfirmationMail($booking, paid: true))->render();

        $this->assertStringContainsString('Payment received: £15.00', $html); // the latest payment
        $this->assertStringContainsString('Paid in full', $html);
        $this->assertStringContainsString('Cash to driver', $html); // the £90 line is tracked
        $this->assertStringContainsString('£115.00', $html); // total
    }
}
