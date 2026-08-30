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

    public function test_one_tap_mark_paid_settles_the_whole_remaining(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 90, 'paid' => 20, 'history' => []]]])->save();

        // One tap marks the £70 that's left as paid → nothing remaining.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'mark_paid', 'from' => 'payroll'])
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertSame(90.0, $booking->driverPaidAmount());
        $this->assertSame(0.0, $booking->driverPayRemaining());
        $this->assertTrue($booking->driverFullyPaid());
        $this->assertSame('Marked paid in full', $booking->driverPayHistory()[0]['note']);
    }

    public function test_mark_paid_is_a_safe_no_op_when_nothing_is_owed(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 50, 'paid' => 50, 'history' => []]]])->save();

        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'mark_paid'])
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertSame(50.0, $booking->driverPaidAmount()); // unchanged, no double-pay
        $this->assertCount(0, $booking->driverPayHistory());
    }

    public function test_the_bookings_list_shows_a_tap_to_pay_action_for_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Complete,
            'pickup_at' => now()->subDay(),
        ]);
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 100, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'all']))
            ->assertOk()
            ->assertSee('tap to pay')
            ->assertSee('markpaid-'.$booking->id, false);
    }

    public function test_payroll_driver_name_links_to_their_directory_details(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Maj Khan']);
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete,
            'pickup_at' => now()->startOfMonth()->addDays(2),
        ])->forceFill(['meta' => ['payroll' => ['pay' => 90, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($admin)->get(route('payroll.index', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee(route('drivers.edit', $driver->id), false);
    }

    public function test_payroll_shows_a_per_driver_airport_filter_with_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Abdi Ali']);
        \App\Models\Airport::create(['code' => 'MAN', 'name' => 'Manchester', 'is_active' => true]);
        \App\Models\Airport::create(['code' => 'LBA', 'name' => 'Leeds Bradford', 'is_active' => true]);
        \Illuminate\Support\Facades\Cache::forget('airport_code_names');
        $when = now()->startOfMonth()->addDays(2);

        $make = fn (array $attrs) => Booking::factory()->create(array_merge([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => $when,
        ], $attrs))->forceFill(['meta' => ['payroll' => ['pay' => 90, 'paid' => 0, 'history' => []]]])->save();

        // A round trip = TWO separate bookings, both MAN: outbound drops off AT
        // Manchester (by name, no code), the return picks up FROM Manchester (by
        // code). No airport_id set (calendar-style).
        $make(['pickup_address' => '10 Home St, Sheffield', 'destination_address' => 'Manchester Airport, Terminal 2']);
        $make(['pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => '10 Home St, Sheffield']);
        // One Leeds Bradford job, and a free-roam job.
        $make(['pickup_address' => 'Leeds Bradford Airport (LBA)', 'destination_address' => '5 Park Rd, Leeds']);
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => $when,
            'pickup_address' => 'Sheffield', 'destination_address' => 'around town',
        ])->forceFill(['meta' => ['where' => 'FREE ROAM', 'payroll' => ['pay' => 90, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($admin)->get(route('payroll.index', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Filter by journey')
            ->assertSee('MAN · 2')       // to MAN (by name) + from MAN (by code) = two jobs
            ->assertSee('LBA · 1')
            ->assertSee('Free Roam · 1');
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
        // Pin mid-month so the pickup (start-of-month + 2) has definitely run,
        // whatever the real date — otherwise it flakes on the 1st/2nd of a month.
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
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

        \Illuminate\Support\Carbon::setTestNow();
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
        $res->assertSee('completed', false)->assertSee('driver paid', false);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_driver_label_shows_name_with_vehicle_reg(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Kash Ali']);
        $booking = Booking::factory()->create(['driver_id' => $driver->id]);
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['driver_details' => ['reg' => 'ab19 xyz']])])->save();

        $this->assertSame('Kash Ali (AB19 XYZ)', $booking->fresh()->driverLabel());
    }

    public function test_payroll_heading_shows_the_reg(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-08-15 12:00:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Kash Ali']);
        $b = Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => '2026-08-10 09:00'])->fresh();
        $b->forceFill(['meta' => array_merge($b->meta ?? [], [
            'driver_details' => ['reg' => 'AB19XYZ'],
            'payroll' => ['pay' => 90, 'paid' => 0, 'history' => []],
        ])])->save();

        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-08']))->assertOk()
            ->assertSee('(AB19XYZ)');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_job_routed_to_a_payee_folds_into_their_payroll_and_hides_the_drivers_owed(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-08-15 12:00:00');
        $admin = User::factory()->admin()->create();
        $kash = User::factory()->driver()->create(['name' => 'Kash']);
        $sub = User::factory()->driver()->create(['name' => 'Sub Driver']);

        // Sub Driver drove it (pay £95) but Kash supplied them — pay Kash.
        $job = Booking::factory()->create(['driver_id' => $sub->id, 'pickup_at' => '2026-08-10 09:00'])
            ->fresh();
        $this->actingAs($admin)->post(route('bookings.payroll', $job), ['action' => 'set', 'amount' => '95'])->assertRedirect();
        $this->actingAs($admin)->post(route('bookings.payroll', $job), ['action' => 'set_payee', 'payee' => 'Kash'])->assertRedirect();

        $this->assertSame('Kash', $job->fresh()->payTo());

        $res = $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-08']))->assertOk();
        $drivers = collect($res->viewData('drivers'));

        // The £95 lands under Kash, and Sub Driver has no card (nothing owed to them).
        $this->assertSame(95.0, $drivers->firstWhere('name', 'Kash')['pay']);
        $this->assertNull($drivers->firstWhere('name', 'Sub Driver'));
        // Kash's card shows who actually drove it.
        $res->assertSee('via Sub Driver');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_payroll_supports_a_custom_date_range_and_a_sendable_statement(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Kash Ali', 'phone' => '07700900321']);
        $airport = \App\Models\Airport::create(['code' => 'EMA', 'name' => 'East Midlands', 'is_active' => true]);
        $customer = \App\Models\Customer::factory()->create(['name' => 'Kerry Moseley']);

        // In-range job (pay £95) and an out-of-range job (must be excluded).
        Booking::factory()->create(['driver_id' => $driver->id, 'customer_id' => $customer->id, 'airport_id' => $airport->id, 'pickup_at' => '2026-08-10 09:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 95, 'paid' => 0, 'history' => []]]])->save();
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => '2026-09-10 09:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 200, 'paid' => 0, 'history' => []]]])->save();

        $res = $this->actingAs($admin)
            ->get(route('payroll.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk();

        // The range label, only the in-range total, and a copyable/sendable statement
        // that uses the customer first name + airport code, NOT the reference.
        $res->assertSee('01 Aug 2026 – 31 Aug 2026')
            ->assertSee('Copy statement')
            ->assertSee('Kerry EMA')
            ->assertSee('Total pay: £95.00', false);

        $driver = collect($res->viewData('drivers'))->firstWhere('name', 'Kash Ali');
        $this->assertSame(95.0, $driver['pay']); // £200 Sept job excluded
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

    public function test_a_balance_pending_line_is_not_read_as_cash_to_the_driver(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Wed Driver']);

        // Wedding-style booking: deposit paid, the balance is settled by CARD to
        // the business. The line says "Balance Pending" — no "cash" — so it must
        // NOT read as cash for the driver to collect.
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'pickup_at' => '2026-07-06 09:00',
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £30 Paid – £320 Balance Pending']])->save();

        $this->assertNull($booking->cashDueToDriver());        // nothing to collect in cash
        $this->assertFalse($booking->driverSettledByCustomer()); // business owes the driver

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('Cash job — settled with the driver');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_office_can_correct_and_confirm_the_cash_amount_on_a_cash_job(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Hamza']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £10 Paid – £98 Cash Due']])->save();

        // Parsed from the line to start with, not yet confirmed.
        $this->assertSame(98.0, $booking->cashDueToDriver());
        $this->assertFalse($booking->cashConfirmed());

        // The office spots the real figure is £90 and confirms it.
        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'confirm_cash', 'amount' => '90'])
            ->assertRedirect();
        $booking = $booking->fresh();

        $this->assertSame(90.0, $booking->cashDueToDriver()); // override wins
        $this->assertTrue($booking->cashConfirmed());
        $this->assertTrue($booking->driverSettledByCustomer());
        $this->assertNull($booking->driverPayRemaining()); // no business pay figure — nothing owed

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('✓ Confirmed')
            ->assertSee('value="90.00"', false);
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

    public function test_completed_jobs_with_no_driver_still_show_on_the_fill_in_list(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();

        // A pre-Command-Centre import: ran this month, no driver, no pay set.
        // The office still wants to fill it in — it must show on the list.
        Booking::factory()->create([
            'driver_id' => null,
            'pickup_at' => '2026-07-05 10:00',
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
        ]);

        $res = $this->actingAs($admin)->get(route('payroll.index'))->assertOk();
        $this->assertSame(1, $res->viewData('missingPay')->count());
        // Header count matches the list exactly.
        $res->assertSee('1 still need pay set');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_callsign_and_login_jobs_total_under_one_driver_in_payroll(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();

        // Abdirazak Hassan, whose callsign is "Abdi".
        $abdi = User::factory()->driver()->create(['name' => 'Abdirazak Hassan']);
        \App\Models\DriverProfile::create(['user_id' => $abdi->id, 'callsign' => 'Abdi']);

        // One job linked to his login…
        Booking::factory()->create(['driver_id' => $abdi->id, 'pickup_at' => '2026-07-05 10:00'])
            ->forceFill(['meta' => ['payroll' => ['pay' => 100, 'paid' => 100, 'history' => []]]])->save();
        // …and one tagged only with the callsign "Abdi" (no login link).
        Booking::factory()->create(['driver_id' => null, 'pickup_at' => '2026-07-06 10:00'])
            ->forceFill(['meta' => ['driver_details' => ['name' => 'Abdi'], 'payroll' => ['pay' => 50, 'paid' => 50, 'history' => []]]])->save();

        // The manual job resolves to the full name, so both land in one group.
        $this->assertSame('Abdirazak Hassan', Booking::resolveDriverFullName('Abdi'));

        $res = $this->actingAs($admin)->get(route('payroll.index'))->assertOk();
        $drivers = $res->viewData('drivers');
        $abdiRow = $drivers->firstWhere('name', 'Abdirazak Hassan');

        $this->assertNotNull($abdiRow);
        $this->assertSame(150.0, $abdiRow['pay']);              // £100 + £50 together
        $this->assertNull($drivers->firstWhere('name', 'Abdi')); // no split "Abdi" group

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_truncated_deposit_line_on_a_cash_job_still_shows_cash_to_collect(): void
    {
        // The exact bug: the calendar Payment line wrapped and only "Deposit £10
        // Paid" survived — the "£130 Cash Due" tail was lost. It's a CASH job with
        // a £140 fare, so the driver must collect £130, NOT "collect nothing".
        $driver = User::factory()->driver()->create(['name' => 'Yaz Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
            'quoted_price' => 140,
            'final_price' => 140,
        ]);
        // Note: no cash amount readable — only the deposit survived the wrap.
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £10 Paid']])->save();

        $this->assertSame(130.0, $booking->cashDueToDriver());
        $this->assertSame('£130 to collect (cash)', $booking->driverCollectLine());
    }

    public function test_a_cash_job_wrongly_stamped_paid_on_import_still_collects_the_fare(): void
    {
        // ETO writes "Deposit £20 paid, balance cash" and the importer, seeing the
        // word "paid", stamps the whole booking payment_status = paid. On a CASH
        // job that must NEVER read as "collect nothing" — the £180 is still due.
        $driver = User::factory()->driver()->create(['name' => 'Import Cash']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
            'payment_status' => 'paid',   // wrongly set by the import
            'quoted_price' => 180,
            'final_price' => 180,
        ]);

        $this->assertSame(180.0, $booking->cashDueToDriver());
        $this->assertSame('£180 to collect (cash)', $booking->driverCollectLine());
    }

    public function test_a_card_note_on_a_cash_method_job_is_still_collected(): void
    {
        // Payment method is card but the office wrote "£90 cash due" on the line —
        // the explicit cash note wins and the driver collects it.
        $driver = User::factory()->driver()->create(['name' => 'Noted Cash']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'quoted_price' => 200,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £110 Paid – £90 Cash Due']])->save();

        $this->assertSame(90.0, $booking->cashDueToDriver());
        $this->assertSame('£90 to collect (cash)', $booking->driverCollectLine());
    }

    public function test_a_cash_job_the_business_collected_by_card_shows_collect_nothing(): void
    {
        // Office marked it: the customer actually paid the business by card. The
        // driver collects nothing even though it's a cash-method job.
        $driver = User::factory()->driver()->create(['name' => 'Biz Card']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
            'quoted_price' => 150,
        ]);
        $booking->forceFill(['meta' => ['payroll' => ['company_collected' => true]]])->save();

        $this->assertNull($booking->cashDueToDriver());
        $this->assertSame('Paid — collect nothing', $booking->driverCollectLine());
    }

    public function test_an_unpaid_cash_job_with_no_line_collects_the_whole_fare(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Plain Cash']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
            'quoted_price' => 75,
        ]);

        $this->assertSame(75.0, $booking->cashDueToDriver());
        $this->assertSame('£75 to collect (cash)', $booking->driverCollectLine());
    }

    public function test_a_card_balance_pending_job_shows_collect_nothing_office_handles_it(): void
    {
        // A CARD job with a pending balance (no "cash" mention): the customer pays
        // the OFFICE by card, and the office chases it — the DRIVER collects
        // nothing. It must NOT tell the driver to collect or to check.
        $driver = User::factory()->driver()->create(['name' => 'Card Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'quoted_price' => 200,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £50 Paid – £150 Balance Pending']])->save();

        $this->assertNull($booking->cashDueToDriver());
        $this->assertFalse($booking->paymentNeedsChecking());
        $this->assertFalse($booking->hasCashToCollect());
        $this->assertStringContainsString('collect nothing', (string) $booking->driverCollectLine());
    }

    public function test_a_card_job_with_a_lost_cash_tail_but_no_cash_word_collects_nothing(): void
    {
        // A card-method job showing only "Deposit £10 Paid" (no "cash" word): the
        // driver collects nothing — the office handles the card balance.
        $driver = User::factory()->driver()->create(['name' => 'Yaz Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'payment_status' => 'paid',
            'quoted_price' => 140, 'final_price' => 140,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £10 Paid']])->save();

        $this->assertFalse($booking->paymentNeedsChecking());
        $this->assertStringContainsString('collect nothing', (string) $booking->driverCollectLine());
    }

    public function test_a_card_job_that_says_cash_still_makes_the_driver_check_or_collect(): void
    {
        // The exception you called out: a booking whose payment line SAYS cash — a
        // remaining cash balance — still makes the driver collect (or check when
        // the amount can't be read), even on a card-method job.
        $driver = User::factory()->driver()->create(['name' => 'Cash Line']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'quoted_price' => 200,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £50 Paid – balance in cash to driver']])->save();

        $this->assertTrue($booking->paymentNeedsChecking());
        $this->assertStringContainsString('check the amount with the office', $booking->driverCollectLine());
        $this->assertStringNotContainsString('collect nothing', (string) $booking->driverCollectLine());
    }

    public function test_a_deposit_plus_cash_due_covering_both_legs_collects_the_cash(): void
    {
        // The exact reported line: "Deposit £20 Paid – £210 Cash Due (covers both
        // legs)". The driver must collect £210, never "collect nothing".
        $driver = User::factory()->driver()->create(['name' => 'Both Legs']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'payment_status' => 'paid',
            'quoted_price' => 230, 'final_price' => 230,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Deposit £20 Paid – £210 Cash Due (covers both legs)']])->save();

        $this->assertSame(210.0, $booking->cashDueToDriver());
        $this->assertSame('£210 to collect (cash)', $booking->driverCollectLine());
    }

    public function test_failsafe_a_cash_word_never_reads_as_collect_nothing(): void
    {
        // Any mention of "cash" with no readable amount must trigger a check,
        // never "collect nothing".
        $driver = User::factory()->driver()->create(['name' => 'Cash Word']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'payment_status' => 'paid',
            'quoted_price' => 100,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Balance in cash to driver']])->save();

        $this->assertStringNotContainsString('collect nothing', (string) $booking->driverCollectLine());
        $this->assertTrue($booking->paymentNeedsChecking());
    }

    public function test_a_fully_prepaid_card_job_tells_the_driver_to_collect_nothing(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Prepaid Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'payment_status' => 'paid',
            'quoted_price' => 120,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Paid £120 (Stripe)']])->save();

        $this->assertNull($booking->cashDueToDriver());
        $this->assertSame('Paid — collect nothing', $booking->driverCollectLine());
    }

    public function test_duplicate_calendar_events_never_flip_the_collect_amount(): void
    {
        // The reported bug: a booking with TWO calendar_events rows — one whose
        // Payment line reads "Paid", one that reads "£130 Cash Due". With an
        // unordered hasOne the driver link flipped between them on each reload.
        // The collect amount must be stable AND favour collecting the cash.
        $booking = Booking::factory()->create([
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'quoted_price' => 140,
            'final_price' => 140,
        ]);
        // Older event: a stale "Paid" line.
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "📑 Booking Confirmation\n• *Payment:* Deposit £10 Paid",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);
        // Newer event: the correct cash-due line.
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "📑 Booking Confirmation\n• *Payment:* Deposit £10 Paid – £130 Cash Due",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        // Stable across repeated fresh loads, and always the cash amount.
        for ($i = 0; $i < 5; $i++) {
            $fresh = Booking::find($booking->id);
            $this->assertSame(130.0, $fresh->cashDueToDriver());
            $this->assertSame('£130 to collect (cash)', $fresh->driverCollectLine());
        }
    }

    public function test_a_paid_only_duplicate_cannot_hide_a_cash_due_line(): void
    {
        // Even if the "Paid" copy happens to be the newest event, the explicit
        // cash figure from the other source still wins — never under-collect.
        $booking = Booking::factory()->create([
            'payment_method' => \App\Enums\PaymentMethod::Card->value,
            'quoted_price' => 200, 'final_price' => 200,
            'meta' => ['payment_text' => 'Deposit £40 Paid – £160 Cash Due'],
        ]);
        // A later calendar event that only says "Paid".
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "📑 Booking Confirmation\n• *Payment:* Paid",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $this->assertSame(160.0, $booking->fresh()->cashDueToDriver());
        $this->assertSame('£160 to collect (cash)', $booking->fresh()->driverCollectLine());
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
