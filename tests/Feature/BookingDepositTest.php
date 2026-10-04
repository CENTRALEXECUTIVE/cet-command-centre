<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A structured deposit on a booking: amount + whether it's paid. When a paid
 * deposit is recorded, the balance the driver collects on a cash job is the fare
 * minus the deposit (£150 fare, £15 deposit → £135 collected).
 */
class BookingDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_editing_records_a_paid_deposit(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'cash', 'quoted_price' => 150,
            'pickup_at' => now()->addDays(2),
        ]);

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Dep Test', 'customer_phone' => '07700900123',
            'journey_type' => 'one_way',
            'pickup_address' => 'A', 'destination_address' => 'B',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 1, 'payment_method' => 'cash',
            'quoted_price' => 150, 'deposit_amount' => 15, 'deposit_paid' => '1',
        ])->assertRedirect();

        $fresh = $booking->fresh();
        $this->assertEqualsWithDelta(15.0, $fresh->depositAmount(), 0.01);
        $this->assertEqualsWithDelta(15.0, $fresh->depositPaidAmount(), 0.01);
    }

    public function test_a_paid_deposit_reduces_the_cash_the_driver_collects(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'cash', 'quoted_price' => 150,
            'pickup_address' => '1 Home St, Sheffield', 'destination_address' => 'Leeds',
            'meta' => ['deposit' => ['amount' => 15, 'paid' => true]],
        ]);

        // £150 fare − £15 paid deposit = £135 for the driver to collect.
        $this->assertEqualsWithDelta(135.0, $booking->cashDueToDriver(), 0.01);
    }

    public function test_an_unpaid_deposit_does_not_reduce_the_balance(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'payment_method' => 'cash', 'quoted_price' => 150,
            'pickup_address' => '1 Home St, Sheffield', 'destination_address' => 'Leeds',
            'meta' => ['deposit' => ['amount' => 15, 'paid' => false]],
        ]);

        // Deposit not paid → the driver still collects the full £150.
        $this->assertEqualsWithDelta(150.0, $booking->cashDueToDriver(), 0.01);
    }
}
