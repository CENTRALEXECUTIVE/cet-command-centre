<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Setting;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every not-yet-done booking gets its driver pay set to the standard 90% of the
 * fare automatically (cet:apply-default-pay), with no "Confirm pay" click.
 */
class ApplyDefaultPayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_it_sets_90_percent_pay_on_a_pending_booking(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'quoted_price' => 200,
        ]);
        $this->assertNull($booking->driverPay());

        $this->artisan('cet:apply-default-pay')->assertSuccessful();

        $this->assertEquals(180.00, $booking->fresh()->driverPay()); // 90% of £200
    }

    public function test_it_respects_a_custom_driver_pay_percent_setting(): void
    {
        Setting::set('driver_pay_percent', 80);
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => 100]);

        $this->artisan('cet:apply-default-pay')->assertSuccessful();

        $this->assertEquals(80.00, $booking->fresh()->driverPay());
    }

    public function test_it_never_overwrites_a_pay_already_on_the_job(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => 200]);
        // An offered cover price already on the job (e.g. a minibus job).
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => ['pay' => 150]])])->save();

        $this->artisan('cet:apply-default-pay')->assertSuccessful();

        $this->assertEquals(150.00, $booking->fresh()->driverPay()); // untouched, not 180
    }

    public function test_it_skips_done_bookings(): void
    {
        $complete = Booking::factory()->create(['status' => BookingStatus::Complete, 'quoted_price' => 200]);
        $cancelled = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'quoted_price' => 200]);

        $this->artisan('cet:apply-default-pay')->assertSuccessful();

        $this->assertNull($complete->fresh()->driverPay());
        $this->assertNull($cancelled->fresh()->driverPay());
    }

    public function test_it_leaves_a_booking_with_no_fare_blank(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => null, 'final_price' => null]);

        $this->artisan('cet:apply-default-pay')->assertSuccessful();

        $this->assertNull($booking->fresh()->driverPay());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending, 'quoted_price' => 200]);

        $this->artisan('cet:apply-default-pay --dry-run')->assertSuccessful();

        $this->assertNull($booking->fresh()->driverPay());
    }
}
