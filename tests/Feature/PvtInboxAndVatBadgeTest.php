<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Non-VAT bookings belong to the PVT LTD company: once PVT email is on, the
 * office copy lands in the PVT inbox. Payroll shows which company each job is.
 */
class PvtInboxAndVatBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_vat_booking_goes_to_the_pvt_inbox_once_pvt_email_is_on(): void
    {
        config(['cet.ops_email' => 'admin@centralexecutivetransfers.co.uk']);
        $nonVat = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);
        $vat = Booking::factory()->create(['meta' => ['billing_entity' => 'transfers', 'vat_invoice_requested' => true]]);

        // Not switched on yet → everything stays in the main inbox.
        $this->assertSame('admin@centralexecutivetransfers.co.uk', $nonVat->officeInbox());

        Setting::set('mail_pvt_enabled', true, 'boolean');
        Setting::set('mail_pvt_from_address', 'admin@centralexecutivetransferspvt.co.uk');

        $this->assertSame('admin@centralexecutivetransferspvt.co.uk', $nonVat->fresh()->officeInbox());
        $this->assertSame('admin@centralexecutivetransfers.co.uk', $vat->fresh()->officeInbox());

        Mail::fake();
        app(\App\Services\BookingStatusService::class)->emailOffice($nonVat->fresh(), 'new');
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->hasTo('admin@centralexecutivetransferspvt.co.uk'));
    }

    public function test_payroll_shows_vat_or_non_vat_under_each_reference(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-28 12:00:00'));
        $driver = User::factory()->driver()->create();
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => now()->subDay(), 'payment_method' => 'card',
            'meta' => ['vat_invoice_requested' => true, 'billing_entity' => 'transfers', 'payroll' => ['pay' => 50, 'paid' => 0, 'history' => []]],
        ]);
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => now()->subDay(), 'payment_method' => 'card',
            'meta' => ['billing_entity' => 'chauffeurs', 'payroll' => ['pay' => 40, 'paid' => 0, 'history' => []]],
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('payroll.index', ['driver' => $driver->id]))
            ->assertOk()
            ->assertSee('Paid into Ltd (Tide)')
            ->assertSee('Paid into PVT LTD')
            ->assertSee('Pay from Ltd (Tide):')
            ->assertSee('£50.00')
            ->assertSee('Pay from PVT LTD:')
            ->assertSee('£40.00');
    }

    public function test_a_pvt_test_through_pvts_own_sender_switches_pvt_sending_on(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.pvt' => ['transport' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587]]);
        Setting::set('mail_pvt_host', 'smtp.gmail.com', 'string', 'mail');
        Mail::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.test-email'), ['to' => 'me@example.com', 'via' => 'pvt'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'now switched ON'));

        $this->assertTrue((bool) Setting::get('mail_pvt_enabled', false));
        $probe = new Booking(['meta' => ['billing_entity' => 'chauffeurs']]);
        $this->assertSame('pvt', $probe->pvtMailer()); // real non-VAT bookings now use it
    }

    public function test_a_pvt_test_with_no_pvt_sender_says_it_went_through_the_main_account(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.test-email'), ['to' => 'me@example.com', 'via' => 'pvt'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'MAIN email account'));

        $this->assertFalse((bool) Setting::get('mail_pvt_enabled', false));
    }

    public function test_settings_shows_where_non_vat_bookings_send_from(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()->assertSee('Non-VAT bookings are using the');

        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_host', 'smtp.gmail.com', 'string', 'mail');
        Setting::set('mail_pvt_from_address', 'admin@centralexecutivetransferspvt.co.uk', 'string', 'mail');
        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()->assertSee('Non-VAT bookings send from', false);
    }

    public function test_money_account_follows_the_payment_then_the_pvt_go_live_date(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-02 12:00:00'));
        $mk = fn (array $a) => Booking::factory()->create(array_merge(['payment_method' => 'card', 'source_system' => null], $a));

        // A real Stripe payment → PVT, whenever it was booked.
        $this->assertSame('pvt', $mk(['created_at' => '2026-10-20 10:00', 'meta' => ['square_payment' => ['id' => 'cs_live_1', 'amount' => 90, 'entity' => 'chauffeurs']]])->moneyAccount()['key']);
        // A real Square payment → Ltd.
        $this->assertSame('ltd', $mk(['created_at' => '2026-10-28 10:00', 'meta' => ['square_payment' => ['id' => 'sq_123', 'amount' => 90, 'entity' => 'transfers']]])->moneyAccount()['key']);
        // Hand-marked paid, non-VAT: by booked date around 25 Oct.
        $this->assertSame('ltd', $mk(['created_at' => '2026-10-24 23:00', 'meta' => ['square_payment' => ['id' => 'MANUAL-1-1', 'amount' => 90, 'entity' => 'chauffeurs']]])->moneyAccount()['key']);
        $this->assertSame('pvt', $mk(['created_at' => '2026-10-25 09:00', 'meta' => []])->moneyAccount()['key']);
        // VAT / account → Ltd; cash → driver; ETO-taken → Ltd.
        $this->assertSame('ltd', $mk(['created_at' => '2026-10-30 09:00', 'meta' => ['vat_invoice_requested' => true]])->moneyAccount()['key']);
        $this->assertSame('cash', $mk(['created_at' => '2026-10-30 09:00', 'payment_method' => 'cash'])->moneyAccount()['key']);
        $this->assertSame('ltd', $mk(['created_at' => '2026-10-30 09:00', 'source_system' => 'eto'])->moneyAccount()['key']);
    }
}
