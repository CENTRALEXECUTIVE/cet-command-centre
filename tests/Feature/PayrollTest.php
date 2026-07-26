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
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $maj = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $abdi = User::factory()->driver()->create(['name' => 'Abdi Ali']);

        // Maj: two jobs this month — £45 (unpaid) + £30 (fully paid).
        Booking::factory()->create(['driver_id' => $maj->id, 'pickup_at' => '2026-07-05 10:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();
        Booking::factory()->create(['driver_id' => $maj->id, 'pickup_at' => '2026-07-07 12:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 30, 'paid' => 30, 'history' => []]]])->save();

        // Abdi: a job that has already RUN with NO pay set → flagged, not totalled.
        Booking::factory()->create(['driver_id' => $abdi->id, 'pickup_at' => '2026-07-06 09:00']);

        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertOk()
            ->assertSee('Maj Khan')
            ->assertSee('£45.00 owed')                       // Maj's remaining
            ->assertSee('completed job(s) have no driver pay set')
            ->assertSee('Abdi Ali');                          // in the missing-pay list

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_job_that_ran_but_was_never_marked_complete_still_needs_pay_set(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Sam Jones']);

        // Ran a fortnight ago, still Allocated (office never tapped Complete),
        // no pay set — this MUST show on the "no pay set" list.
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Allocated,
            'pickup_at' => '2026-07-06 09:00',
        ]);

        $res = $this->actingAs($admin)->get(route('payroll.index'))->assertOk();
        $this->assertSame(1, $res->viewData('missingPay')->count());
        $res->assertSee('Sam Jones');

        \Illuminate\Support\Carbon::setTestNow();
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

    public function test_payroll_page_counts_completed_jobs_this_month_not_upcoming(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();

        // Two jobs that have already RUN this month.
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => '2026-07-05 09:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 40, 'paid' => 40, 'history' => []]]])->save(); // paid
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => '2026-07-10 09:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 50, 'paid' => 0, 'history' => []]]])->save();  // owed

        // An UPCOMING job this month — must NOT count (the job hasn't run).
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => '2026-07-28 09:00']);
        // A cancelled job this month — must NOT count.
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => '2026-07-06 09:00', 'status' => BookingStatus::Cancelled]);

        $res = $this->actingAs($admin)->get(route('payroll.index'))->assertOk();

        $this->assertSame(2, $res->viewData('completedCount')); // only the two that ran
        $this->assertSame(1, $res->viewData('paidCount'));      // one of them fully paid
        $res->assertSee('completed this month', false)->assertSee('driver paid', false);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_cash_jobs_are_settled_by_the_customer_so_the_business_owes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Cash Carl']);

        // A cash job: the customer pays the driver directly. Pay is £45, the
        // business has handed over nothing — but it owes nothing either.
        $cash = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
        ]);
        $cash->forceFill(['meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();

        $this->assertTrue($cash->driverSettledByCustomer());
        $this->assertSame(0.0, $cash->driverPayRemaining()); // business owes nothing

        // A card job with the same pay still owes the driver from the business.
        $card = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
        ]);
        $card->forceFill(['meta' => ['payroll' => ['pay' => 45, 'paid' => 0, 'history' => []]]])->save();

        $this->assertFalse($card->driverSettledByCustomer());
        $this->assertSame(45.0, $card->driverPayRemaining());

        // The booking page shows the cash job as settled with the driver, not owed.
        $this->actingAs($admin)->get(route('bookings.show', $cash))
            ->assertOk()
            ->assertSee('Cash job — settled with the driver')
            ->assertDontSee('£45.00 owed');
    }

    public function test_deposit_plus_cash_jobs_are_settled_by_the_customer_and_never_ask_for_pay(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Majid Ali']);

        // The exact screenshot case: deposit paid, balance cash to the driver.
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'pickup_at' => '2026-07-06 09:00',
            'payment_method' => \App\Enums\PaymentMethod::Card->value, // deposit was card
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £10 Paid – £90 Cash Due']])->save();

        $this->assertTrue($booking->driverSettledByCustomer());
        $this->assertSame(90.0, $booking->cashDueToDriver());

        // Booking page: shows it as settled with the driver, no "set pay" demand.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Cash job — settled with the driver')
            ->assertSee('The driver collects')
            ->assertDontSee('No driver pay set for this job yet.');

        // Payroll page: NOT on the "still to pay" list, and counted as paid.
        $res = $this->actingAs($admin)->get(route('payroll.index'))->assertOk();
        $this->assertSame(0, $res->viewData('missingPay')->count());
        $this->assertSame(1, $res->viewData('completedCount'));
        $this->assertSame(1, $res->viewData('paidCount'));

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_cash_job_paid_by_card_to_the_business_flips_to_the_business_owing(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Majid Ali']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
        ]);

        // Starts settled with the driver (normal cash job).
        $this->assertTrue($booking->driverSettledByCustomer());

        // Office marks it: actually paid by card to the business.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'company_collected', 'collected' => '1'])
            ->assertRedirect();
        $booking = $booking->fresh();

        $this->assertTrue($booking->businessCollectedCash());
        $this->assertFalse($booking->driverSettledByCustomer()); // business owes now

        // Set the pay — it's owed by the business, just like a card job.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'set', 'amount' => '45'])
            ->assertRedirect();
        $this->assertSame(45.0, $booking->fresh()->driverPayRemaining());

        // Undo → back to cash-settled.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'company_collected', 'collected' => '0'])
            ->assertRedirect();
        $this->assertTrue($booking->fresh()->driverSettledByCustomer());
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
