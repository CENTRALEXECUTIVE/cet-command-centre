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
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-15 12:00:00'));
        $driver = User::factory()->driver()->create();
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => now()->subDay(),
            'meta' => ['vat_invoice_requested' => true, 'billing_entity' => 'transfers', 'payroll' => ['pay' => 50, 'paid' => 0, 'history' => []]],
        ]);
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => now()->subDay(),
            'meta' => ['billing_entity' => 'chauffeurs', 'payroll' => ['pay' => 40, 'paid' => 0, 'history' => []]],
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('payroll.index', ['driver' => $driver->id]))
            ->assertOk()
            ->assertSee('VAT · Ltd')
            ->assertSee('Non-VAT · PVT');
    }
}
