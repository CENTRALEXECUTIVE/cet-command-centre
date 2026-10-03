<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\WatchdogEvent;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When ETO amends or cancels a LIVE (upcoming) booking, the change still applies
 * — but the office must also be TOLD, so a driver isn't left heading to a time or
 * place ETO has already moved. Routine re-ingests and office-pinned fields stay
 * silent.
 */
class EtoAmendmentAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    private function parsed(array $overrides = []): array
    {
        return array_merge([
            'is_booking' => true, 'cancelled' => false, 'reference' => 'LIVE01',
            'customer_name' => 'James Watson', 'customer_email' => 'james@example.com',
            'customer_phone' => '07700900123',
            'pickup_address' => 'Manchester Airport (MAN), Terminal 3',
            'destination_address' => 'Radisson Blu Hotel, Sheffield',
            'pickup_at' => now()->addDays(3)->setTime(14, 0)->format('Y-m-d H:i'),
            'passengers' => 2, 'vehicle_type' => 'Executive', 'flight_number' => 'BA123',
            'payment_status' => 'paid', 'payment_method' => 'Square',
        ], $overrides);
    }

    public function test_an_amended_pickup_time_on_a_live_job_alerts_the_office(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        // ETO moves the pickup an hour later.
        $result = $svc->upsertFromParsed($this->parsed([
            'pickup_at' => now()->addDays(3)->setTime(15, 0)->format('Y-m-d H:i'),
        ]));

        $this->assertSame('updated', $result['action']);
        $booking = Booking::where('external_reference', 'LIVE01')->first();
        $this->assertSame('15:00', $booking->pickup_at->format('H:i'));  // applied

        $event = WatchdogEvent::where('event_type', 'eto_amended')->where('booking_id', $booking->id)->first();
        $this->assertNotNull($event, 'the office is alerted to the amendment');
        $this->assertStringContainsString('pickup', $event->title);
    }

    public function test_a_routine_reingest_with_no_change_does_not_alert(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());
        // Same details again — nothing material changed.
        $svc->upsertFromParsed($this->parsed());

        $this->assertSame(0, WatchdogEvent::where('event_type', 'eto_amended')->count());
    }

    public function test_an_office_pinned_time_is_not_reported_as_an_eto_change(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        // The office pins the pickup time in the app.
        $booking = Booking::where('external_reference', 'LIVE01')->first();
        $booking->forceFill([
            'pickup_at' => now()->addDays(3)->setTime(18, 30),
            'meta' => array_merge($booking->meta ?? [], ['edited_fields' => ['pickup_at']]),
        ])->save();

        // ETO re-sends a different time — it must be blocked AND not alerted.
        $svc->upsertFromParsed($this->parsed([
            'pickup_at' => now()->addDays(3)->setTime(16, 0)->format('Y-m-d H:i'),
        ]));

        $this->assertSame('18:30', $booking->fresh()->pickup_at->format('H:i')); // office wins
        $this->assertSame(0, WatchdogEvent::where('event_type', 'eto_amended')->count());
    }

    public function test_an_eto_cancellation_of_a_live_job_alerts_and_cancels(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        $result = $svc->upsertFromParsed(['is_booking' => true, 'cancelled' => true, 'reference' => 'LIVE01']);

        $this->assertSame('cancelled', $result['action']);
        $booking = Booking::where('external_reference', 'LIVE01')->first();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertTrue(
            WatchdogEvent::where('event_type', 'eto_amended')->where('booking_id', $booking->id)->exists(),
            'the office is told ETO pulled the job'
        );
    }
}
