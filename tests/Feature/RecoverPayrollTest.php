<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoverPayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_pay_from_the_audit_log_onto_a_wiped_booking(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $booking = Booking::factory()->create();

        // Office set the pay (audit log captures the new meta).
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'payroll' => ['pay' => 45, 'paid' => 20, 'history' => []],
        ])])->save();

        // The old ingest bug then wiped meta.payroll.
        $booking->forceFill(['meta' => ['lead_name' => 'James']])->save();
        $this->assertNull($booking->fresh()->driverPay());

        $this->artisan('cet:recover-payroll')->assertSuccessful();

        $booking = $booking->fresh();
        $this->assertSame(45.0, $booking->driverPay());
        $this->assertSame(20.0, $booking->driverPaidAmount());
    }

    public function test_it_never_clobbers_pay_that_is_currently_set(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $booking = Booking::factory()->create();

        // An old audited value…
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();
        // …but the office has since re-entered a different, current value.
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 60, 'paid' => 0, 'history' => []]]])->save();

        $this->artisan('cet:recover-payroll')->assertSuccessful();

        // The current value stands — recovery only fills blanks.
        $this->assertSame(60.0, $booking->fresh()->driverPay());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $booking = Booking::factory()->create();
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();
        $booking->forceFill(['meta' => []])->save();

        $this->artisan('cet:recover-payroll --dry-run')->assertSuccessful();

        $this->assertNull($booking->fresh()->driverPay());
    }
}
