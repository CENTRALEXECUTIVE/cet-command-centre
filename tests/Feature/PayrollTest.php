<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sets_driver_pay_and_records_payments(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);

        // Set what the job pays.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'set', 'amount' => '45'])
            ->assertRedirect();
        $booking = $booking->fresh();
        $this->assertSame(45.0, $booking->driverPay());
        $this->assertSame(45.0, $booking->driverPayRemaining());

        // Record a part payment…
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'record', 'amount' => '20', 'note' => 'cash Friday'])
            ->assertRedirect();
        $booking = $booking->fresh();
        $this->assertSame(20.0, $booking->driverPaidAmount());
        $this->assertSame(25.0, $booking->driverPayRemaining());

        // …then the rest → settled, with a two-entry history.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'record', 'amount' => '25'])
            ->assertRedirect();
        $booking = $booking->fresh();
        $this->assertSame(0.0, $booking->driverPayRemaining());
        $this->assertCount(2, $booking->driverPayHistory());
        $this->assertSame('cash Friday', $booking->driverPayHistory()[0]['note']);
    }

    public function test_booking_page_shows_the_payroll_section(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Abdi Ali']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 60, 'paid' => 10, 'history' => []]]])->save();

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Driver payroll — Abdi Ali')
            ->assertSee('£50.00 owed');
    }

    public function test_payroll_page_totals_per_driver_and_flags_missing_pay(): void
    {
        $admin = User::factory()->admin()->create();
        $maj = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $abdi = User::factory()->driver()->create(['name' => 'Abdi Ali']);

        // Maj: two jobs this month — £45 (unpaid) + £30 (fully paid).
        Booking::factory()->create(['driver_id' => $maj->id, 'pickup_at' => now()->startOfMonth()->addDays(3)->setTime(10, 0)])
            ->forceFill(['meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();
        Booking::factory()->create(['driver_id' => $maj->id, 'pickup_at' => now()->startOfMonth()->addDays(5)->setTime(12, 0)])
            ->forceFill(['meta' => ['payroll' => ['pay' => 30, 'paid' => 30, 'history' => []]]])->save();

        // Abdi: a completed job with NO pay set → flagged, not totalled.
        Booking::factory()->create([
            'driver_id' => $abdi->id, 'status' => BookingStatus::Complete,
            'pickup_at' => now()->startOfMonth()->addDays(4)->setTime(9, 0),
        ]);

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertOk()
            ->assertSee('Maj Khan')
            ->assertSee('£45.00 owed')                       // Maj's remaining
            ->assertSee('completed job(s) have no driver pay set')
            ->assertSee('Abdi Ali');                          // in the missing-pay list
    }

    public function test_drivers_cannot_touch_payroll(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);

        $this->actingAs($driver)
            ->post(route('bookings.payroll', $booking), ['action' => 'set', 'amount' => '45'])
            ->assertForbidden();
        $this->actingAs($driver)->get(route('payroll.index'))->assertForbidden();
    }
}
