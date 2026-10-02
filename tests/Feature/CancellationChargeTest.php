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
            ->assertSee('Cancellation outcome')
            ->assertSee('Charge 50%')
            ->assertSee('Refund to customer')
            ->assertSee('Full refund');
    }

    public function test_recording_a_full_refund_marks_the_job_refunded(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);
        // Customer had paid in full before cancelling.
        $booking->forceFill(['payment_status' => 'paid'])->save();

        $this->actingAs($admin)->post(route('bookings.cancellation-charge', $booking), [
            'fee' => '0', 'refund' => '105', 'refund_reason' => 'Cancelled in good time',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertTrue($booking->hasCancellationRefund());
        $this->assertSame(105.0, $booking->cancellationRefund());
        $this->assertSame('Cancelled in good time', $booking->cancellationRefundReason());
        $this->assertFalse($booking->hasCancellationCharge());
        $this->assertSame('refunded', $booking->payment_status);

        // A fully-refunded cancellation is no revenue.
        $reports = app(\App\Services\Reporting\ReportService::class);
        $summary = $reports->summary(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(0.0, $summary['revenue']);
    }

    public function test_a_part_charge_and_part_refund_coexist(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);
        $booking->forceFill(['payment_status' => 'paid'])->save();

        // Keep £52.50, refund the other half.
        $this->actingAs($admin)->post(route('bookings.cancellation-charge', $booking), [
            'fee' => '52.50', 'driver_pay' => '30', 'refund' => '52.50',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertTrue($booking->hasCancellationCharge());
        $this->assertSame(52.5, $booking->cancellationFee());
        $this->assertTrue($booking->hasCancellationRefund());
        $this->assertSame(52.5, $booking->cancellationRefund());

        // The kept £52.50 still counts in revenue.
        $reports = app(\App\Services\Reporting\ReportService::class);
        $summary = $reports->summary(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(52.5, $summary['revenue']);
    }

    public function test_removing_a_refund_restores_the_paid_status(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->cancelledJob(105);
        $booking->forceFill(['payment_status' => 'paid'])->save();
        $booking->setCancellationRefund(105, 'oops', $admin);
        $this->assertSame('refunded', $booking->fresh()->payment_status);

        $this->actingAs($admin)->post(route('bookings.cancellation-charge', $booking), ['clear_refund' => '1'])
            ->assertRedirect();

        $booking->refresh();
        $this->assertFalse($booking->hasCancellationRefund());
        $this->assertSame('paid', $booking->payment_status);
    }

    public function test_only_admins_can_set_a_cancellation_charge(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->cancelledJob(105);
        $this->actingAs($driver)->post(route('bookings.cancellation-charge', $booking), ['fee' => '52.50'])->assertForbidden();
    }
}
