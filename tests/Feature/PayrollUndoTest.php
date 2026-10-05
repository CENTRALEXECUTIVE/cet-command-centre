<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A driver payment recorded by mistake (e.g. "marked paid in full" when the
 * driver wasn't actually paid) can be reverted per entry, putting the amount
 * back as owed. Our records only — never touches the customer's payment.
 */
class PayrollUndoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_undo_reverts_a_marked_paid_payment(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Abdirazak Hassan']);
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id, 'driver_id' => $driver->id,
            'status' => 'complete', 'payment_method' => 'card',
            'meta' => ['payroll' => ['pay' => 94.50, 'paid' => 0, 'history' => []]],
        ]);

        // Mark paid in full.
        $this->actingAs($admin)->post(route('bookings.payroll', $booking), ['action' => 'mark_paid'])->assertRedirect();
        $booking->refresh();
        $this->assertEqualsWithDelta(94.50, $booking->driverPaidAmount(), 0.01);
        $this->assertEqualsWithDelta(0.0, $booking->driverPayRemaining(), 0.01);
        $this->assertCount(1, $booking->driverPayHistory());

        // Undo it — the driver is owed the £94.50 again.
        $this->actingAs($admin)->post(route('bookings.payroll', $booking), [
            'action' => 'undo_payment', 'index' => 0,
        ])->assertRedirect();

        $booking->refresh();
        $this->assertEqualsWithDelta(0.0, $booking->driverPaidAmount(), 0.01);
        $this->assertEqualsWithDelta(94.50, $booking->driverPayRemaining(), 0.01);
        $this->assertSame([], $booking->driverPayHistory());
    }

    public function test_undo_of_a_missing_entry_is_harmless(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'vehicle_type_id' => $exec->id,
            'meta' => ['payroll' => ['pay' => 50, 'paid' => 0, 'history' => []]],
        ]);

        $this->actingAs($admin)->post(route('bookings.payroll', $booking), [
            'action' => 'undo_payment', 'index' => 5,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(0.0, $booking->fresh()->driverPaidAmount(), 0.01);
    }
}
