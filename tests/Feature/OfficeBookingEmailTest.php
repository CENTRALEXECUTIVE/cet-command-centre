<?php

namespace Tests\Feature;

use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The office (ops inbox) is notified at each step: a new booking is created,
 * and later when its fare is paid online.
 */
class OfficeBookingEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cet.ops_email' => 'office@example.com']);
    }

    public function test_the_office_is_emailed_a_new_booking(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        app(BookingStatusService::class)->emailOffice($booking->fresh(), 'new');

        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'new'
            && $m->hasTo('office@example.com')
            && $m->booking->id === $booking->id);
        $this->assertNotEmpty($booking->fresh()->meta['office_email_new_sent'] ?? null);
    }

    public function test_the_office_is_emailed_on_payment(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'paid']);

        app(BookingStatusService::class)->emailOffice($booking->fresh(), 'paid');

        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'paid'
            && $m->hasTo('office@example.com'));
    }

    public function test_each_office_stage_is_sent_once(): void
    {
        Mail::fake();
        $booking = Booking::factory()->create();
        $svc = app(BookingStatusService::class);

        $svc->emailOffice($booking->fresh(), 'new');
        $svc->emailOffice($booking->fresh(), 'new');
        $svc->emailOffice($booking->fresh(), 'paid');

        // 'new' once, 'paid' once — the repeat 'new' is suppressed.
        Mail::assertSent(OfficeBookingMail::class, 2);
    }

    public function test_no_office_email_when_no_ops_inbox_is_set(): void
    {
        config(['cet.ops_email' => null]);
        Mail::fake();
        $booking = Booking::factory()->create();

        app(BookingStatusService::class)->emailOffice($booking->fresh(), 'new');

        Mail::assertNothingSent();
    }
}
