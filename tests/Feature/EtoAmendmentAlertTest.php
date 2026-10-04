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
 * ETO amendments and cancellations apply to the booking SILENTLY — no push and no
 * entry in the alerts feed. The office runs the Command Centre and doesn't want
 * routine ETO changes cluttering the alerts.
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

    public function test_an_amended_pickup_time_applies_but_does_not_alert(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        $result = $svc->upsertFromParsed($this->parsed([
            'pickup_at' => now()->addDays(3)->setTime(15, 0)->format('Y-m-d H:i'),
        ]));

        $this->assertSame('updated', $result['action']);
        $booking = Booking::where('external_reference', 'LIVE01')->first();
        $this->assertSame('15:00', $booking->pickup_at->format('H:i'));  // applied

        // No alerts-feed noise at all.
        $this->assertSame(0, WatchdogEvent::where('event_type', 'eto_amended')->count());
    }

    public function test_office_pinned_time_still_wins_over_eto(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        $booking = Booking::where('external_reference', 'LIVE01')->first();
        $booking->forceFill([
            'pickup_at' => now()->addDays(3)->setTime(18, 30),
            'meta' => array_merge($booking->meta ?? [], ['edited_fields' => ['pickup_at']]),
        ])->save();

        $svc->upsertFromParsed($this->parsed([
            'pickup_at' => now()->addDays(3)->setTime(16, 0)->format('Y-m-d H:i'),
        ]));

        $this->assertSame('18:30', $booking->fresh()->pickup_at->format('H:i')); // office wins
        $this->assertSame(0, WatchdogEvent::where('event_type', 'eto_amended')->count());
    }

    public function test_an_eto_cancellation_cancels_silently(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        $result = $svc->upsertFromParsed(['is_booking' => true, 'cancelled' => true, 'reference' => 'LIVE01']);

        $this->assertSame('cancelled', $result['action']);
        $this->assertSame(BookingStatus::Cancelled, Booking::where('external_reference', 'LIVE01')->first()->status);
        $this->assertSame(0, WatchdogEvent::where('event_type', 'eto_amended')->count());
    }
}
