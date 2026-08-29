<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A cancelled/no-show job that's still charged (e.g. 50%): the fee counts in
 * revenue and the driver's share flows to payroll, instead of the whole job
 * vanishing the way a free cancellation does.
 */
class CancellationChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Carbon::setTestNow('2026-08-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cancelledJob(float $fare = 105): Booking
    {
        $driver = User::factory()->driver()->create(['name' => 'Richard']);

        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Lloyd Oyefuwa'])->id,
                'driver_id' => $driver->id,
                'pickup_at' => now()->addHours(6),
                'status' => BookingStatus::Cancelled->value,
                'payment_method' => 'card',
                'quoted_price' => $fare, 'final_price' => $fare,
            ]);
    }

    public function test_setting_a_charge_records_fee_driver_pay_and_final_price(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);

        $this->actingAs($admin)->post(route('bookings.cancellation-charge', $booking), [
            'fee' => '52.50', 'driver_pay' => '30',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertTrue($booking->hasCancellationCharge());
        $this->assertSame(52.5, $booking->cancellationFee());
        $this->assertSame(30.0, $booking->cancellationDriverPay());
        $this->assertSame(105.0, $booking->cancellationOriginalFare());
        // The fee becomes the fare so revenue counts it; driver pay flows to payroll.
        $this->assertSame(52.5, $booking->fareAmount());
        $this->assertSame(30.0, $booking->driverPay());
    }

    public function test_charged_cancellation_counts_in_revenue(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);
        $booking->setCancellationCharge(52.50, 30, $admin);

        $reports = app(\App\Services\Reporting\ReportService::class);
        $summary = $reports->summary(now()->startOfMonth(), now()->endOfMonth());

        // The £52.50 fee is counted, not the £105 fare and not £0.
        $this->assertSame(52.5, $summary['revenue']);
        $this->assertSame(1, $summary['jobs']);
    }

    public function test_a_free_cancellation_still_counts_for_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $this->cancelledJob(105); // no charge set

        $reports = app(\App\Services\Reporting\ReportService::class);
        $summary = $reports->summary(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(0.0, $summary['revenue']);
        $this->assertSame(0, $summary['jobs']);
    }

    public function test_charged_cancellation_shows_on_payroll(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);
        $booking->setCancellationCharge(52.50, 30, $admin);

        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('Richard')
            ->assertSee('30.00');
    }

    public function test_clearing_the_charge_restores_the_original_fare(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);
        $booking->setCancellationCharge(52.50, 30, $admin);

        $this->actingAs($admin)->post(route('bookings.cancellation-charge', $booking), ['clear' => '1'])->assertRedirect();

        $booking->refresh();
        $this->assertFalse($booking->hasCancellationCharge());
        $this->assertSame(105.0, $booking->fareAmount());
    }

    public function test_the_booking_page_offers_the_charge_form_for_a_cancelled_job(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Cancelled — charge', false)
            ->assertSee('Charge 50%');
    }

    public function test_only_admins_can_set_a_cancellation_charge(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->cancelledJob(105);
        $this->actingAs($driver)->post(route('bookings.cancellation-charge', $booking), ['fee' => '52.50'])->assertForbidden();
    }
}
