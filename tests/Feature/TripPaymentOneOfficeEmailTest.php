<?php

namespace Tests\Feature;

use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 27 Nov 2026 (Claire Green, CET-14053A/B): one £264 payment for a return sent the
 * office two "Payment received" emails, and the outbound's page showed no email sent
 * because the confirmation went from the return leg.
 */
class TripPaymentOneOfficeEmailTest extends TestCase
{
    use RefreshDatabase;

    private function trip(): array
    {
        config(['cet.ops_email' => 'office@example.com']);
        $c = Customer::create(['name' => 'Claire Example', 'email' => 'claire@example.com', 'phone' => '07700900666']);
        [$o, $r] = Booking::generateReferencePair();
        $common = ['customer_id' => $c->id, 'payment_method' => 'card', 'payment_status' => 'pending', 'journey_type' => 'return', 'source' => 'web',
            'status' => \App\Enums\BookingStatus::Pending->value];
        $out = Booking::factory()->create($common + ['reference' => $o, 'quoted_price' => 110, 'pickup_at' => now()->addDays(10)]);
        $ret = Booking::factory()->create($common + ['reference' => $r, 'is_return_leg' => true, 'quoted_price' => 110, 'pickup_at' => now()->addDays(11)]);
        $out->forceFill(['linked_booking_id' => $ret->id])->save();
        $ret->forceFill(['linked_booking_id' => $out->id])->save();

        return [$out->fresh(), $ret->fresh()];
    }

    public function test_one_trip_payment_sends_the_office_one_email_and_both_legs_show_the_confirmation(): void
    {
        Mail::fake();
        [$out, $ret] = $this->trip();
        $total = (float) $out->amountPayable() + (float) $ret->amountPayable();
        // The customer pays the whole trip on the RETURN leg's link.
        $ret->markFarePaid('sq_pay_1', $total, 'transfers');
        app(BookingStatusService::class)->onFarePaid($ret->fresh());
        // A later sweep / webhook touching the outbound must not send a second one.
        app(BookingStatusService::class)->onFarePaid($out->fresh());
        app(BookingStatusService::class)->emailOffice($out->fresh(), 'paid');

        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid');
        $this->assertSame(1, Mail::sent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid')->count(), 'one office email per trip payment');
    }

    public function test_the_other_legs_page_shows_the_confirmation_was_emailed(): void
    {
        [$out, $ret] = $this->trip();
        $ret->forceFill(['meta' => array_merge($ret->meta ?? [], ['email_log' => [[
            'id' => 'x1', 'at' => now()->toIso8601String(), 'type' => 'Paid confirmation', 'audience' => 'customer',
            'to' => 'claire@example.com', 'subject' => 'Central Executive Transfers booking confirmation '.$ret->reference, 'ok' => true,
        ]]])])->save();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.show', $out))->assertOk()
            ->assertSee('Paid confirmation emailed to claire@example.com')
            ->assertSee('sent from '.$ret->reference);
        $this->actingAs($admin)->get(route('bookings.show', $ret))->assertOk()
            ->assertSee('Paid confirmation emailed to claire@example.com');
    }

    public function test_no_confirmation_yet_is_said_plainly(): void
    {
        [$out] = $this->trip();
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.show', $out))->assertOk()
            ->assertSee('No confirmation has been emailed to the customer yet.');
    }
}
