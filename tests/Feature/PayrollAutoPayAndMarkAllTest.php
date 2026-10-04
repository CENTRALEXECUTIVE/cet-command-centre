<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Payroll convenience:
 *  - driver pay auto-fills to 90% of the fare the moment a job is allocated, so
 *    "driver owed" is already right without confirming each one (overridable);
 *  - a per-driver "Mark all paid" settles every outstanding job in the period.
 */
class PayrollAutoPayAndMarkAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_allocating_a_job_auto_fills_90_percent_driver_pay(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending, 'quoted_price' => 100.00,
            'pickup_at' => now()->addDays(2),
        ]);

        app(BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $this->assertEqualsWithDelta(90.0, $booking->fresh()->driverPay(), 0.01); // 90% of £100
    }

    public function test_auto_fill_never_overwrites_a_price_already_offered(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending, 'quoted_price' => 100.00,
            'pickup_at' => now()->addDays(2),
            'meta' => ['payroll' => ['pay' => 70, 'paid' => 0, 'history' => []]], // offered price
        ]);

        app(BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $this->assertEqualsWithDelta(70.0, $booking->fresh()->driverPay(), 0.01); // kept, not overwritten
    }

    public function test_mark_all_paid_settles_every_job_for_a_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Owed Olly']);

        $make = fn () => Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete,
            'pickup_at' => Carbon::parse('2026-10-10 09:00'),
            'meta' => ['payroll' => ['pay' => 90, 'paid' => 0, 'history' => []]],
        ]);
        $a = $make();
        $b = $make();

        $this->actingAs($admin)->post(route('payroll.mark-driver-paid'), [
            'payee' => 'Owed Olly', 'from' => '2026-10-01', 'to' => '2026-10-31',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(0.0, $a->fresh()->driverPayRemaining(), 0.01);
        $this->assertEqualsWithDelta(0.0, $b->fresh()->driverPayRemaining(), 0.01);
    }

    public function test_mark_all_paid_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->post(route('payroll.mark-driver-paid'), ['payee' => 'X'])->assertForbidden();
    }
}
