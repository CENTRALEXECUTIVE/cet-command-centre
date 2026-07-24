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

    public function test_admin_logs_cash_and_card_tips_for_the_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);

        // A cash tip the driver already has.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'tip', 'amount' => '5', 'method' => 'cash'])
            ->assertRedirect();
        // A card tip the company collected → owed to the driver.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'tip', 'amount' => '10', 'method' => 'card', 'note' => 'Square'])
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertSame(15.0, $booking->tipsTotal());
        $this->assertSame(5.0, $booking->tipsTotalBy('cash'));
        $this->assertSame(10.0, $booking->cardTipsOwed());
        $this->assertCount(2, $booking->tips());
        $this->assertSame('Square', $booking->tips()[1]['note']);
    }

    public function test_a_tip_requires_a_method(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['driver_id' => User::factory()->driver()->create()->id]);

        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'tip', 'amount' => '5'])
            ->assertSessionHasErrors('method');
    }

    public function test_tips_show_on_the_payroll_page_even_without_pay_set(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Tip Only Tom']);

        // A job with a tip but no driver pay set — should still surface on payroll.
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => now()->startOfMonth()->addDays(2)->setTime(9, 0)])
            ->logTip(8.0, 'card', loggedBy: 'Abdi');

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertOk()
            ->assertSee('Tip Only Tom')
            ->assertSee('£8.00 tips');
    }

    public function test_setting_pay_from_the_payroll_list_returns_there_and_clears_the_job(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete,
            'pickup_at' => now()->startOfMonth()->addDays(2)->setTime(10, 0),
        ]);
        $month = now()->format('Y-m');

        // Starts on the missing-pay list.
        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertSee('completed job(s) have no driver pay set');

        // Inline set from the list → lands back on the list, not the booking page.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), [
                'action' => 'set', 'amount' => '50', 'from' => 'payroll', 'month' => $month,
            ])
            ->assertRedirect(route('payroll.index', ['month' => $month]).'#missing-pay');

        $this->assertSame(50.0, $booking->fresh()->driverPay());

        // …and the job is gone from the list (fresh render).
        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertDontSee('completed job(s) have no driver pay set');
    }

    public function test_setting_pay_from_the_booking_page_returns_to_the_payroll_section(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['driver_id' => User::factory()->driver()->create()->id]);

        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'set', 'amount' => '40'])
            ->assertRedirect(route('bookings.show', $booking).'#payroll');
    }

    public function test_payroll_page_shows_month_booking_and_paid_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $when = fn (int $d) => now()->startOfMonth()->addDays($d)->setTime(9, 0);

        // Fully paid.
        Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => $when(2)])
            ->forceFill(['meta' => ['payroll' => ['pay' => 40, 'paid' => 40, 'history' => []]]])->save();
        // Pay set but still owed.
        Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => $when(3)])
            ->forceFill(['meta' => ['payroll' => ['pay' => 50, 'paid' => 0, 'history' => []]]])->save();
        // No pay set at all.
        Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => $when(4)]);

        $res = $this->actingAs($admin)->get(route('payroll.index'))->assertOk();

        $this->assertSame(3, $res->viewData('bookingCount'));  // three jobs this month
        $this->assertSame(1, $res->viewData('paidCount'));     // one paid in full
        $res->assertSee('bookings this month', false)->assertSee('paid in full', false);
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
