<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Waiting time. Once the driver is AT the pickup with location sharing on, the
 * customer gets a free grace period; only after it does billable waiting time
 * start. The clock is anchored to a GPS ping that confirms the driver at the
 * pickup — NOT the "Arrived" tap — so tapping Arrived from home never runs it.
 * No messages are sent; no money is shown.
 */
class WaitingTimeTest extends TestCase
{
    use RefreshDatabase;

    // A pickup point and a ping right on it (well inside the geofence).
    private const PICKUP = [53.3811, -1.4701];

    private const HOME = [53.4200, -1.5200]; // ~4 km away — outside the geofence

    /**
     * @param  array|null  $pickup     geocoded pickup coords stored on the booking
     * @param  array|null  $pingCoord  where the driver's GPS puts them (null = no ping)
     */
    private function arrived(Carbon $arrivedAt, ?array $pickup = self::PICKUP, ?array $pingCoord = self::PICKUP, ?Carbon $pingAt = null): Booking
    {
        $driver = User::factory()->driver()->create(['name' => 'Wait Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Arrived,
        ]);
        if ($pickup) {
            $booking->forceFill(['meta' => ['geo' => ['pickup' => $pickup]]])->save();
        }
        $booking->statusHistory()->create([
            'from_status' => BookingStatus::EnRoute->value,
            'to_status' => BookingStatus::Arrived->value,
            'changed_by' => $driver->id,
            'created_at' => $arrivedAt,
        ]);
        if ($pingCoord) {
            $booking->driverLocations()->create([
                'driver_id' => $driver->id,
                'latitude' => $pingCoord[0],
                'longitude' => $pingCoord[1],
                'captured_at' => $pingAt ?? $arrivedAt,
            ]);
        }

        return $booking->fresh();
    }

    public function test_no_billable_time_during_the_grace_period(): void
    {
        Carbon::setTestNow('2026-08-11 08:10:00');
        // At the pickup 8 minutes → inside the free 15-minute grace.
        $booking = $this->arrived(Carbon::parse('2026-08-11 08:02:00'));

        $this->assertTrue($booking->waitingConfirmedAtPickup());
        $this->assertSame(0, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_billable_time_counts_only_past_the_grace(): void
    {
        Carbon::setTestNow('2026-08-11 08:30:00');
        // At the pickup 25 minutes → 25 − 15 grace = 10 billable minutes.
        $booking = $this->arrived(Carbon::parse('2026-08-11 08:05:00'));

        $this->assertSame(10, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_tapping_arrived_from_home_does_not_start_the_timer(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        // Tapped Arrived 30 min ago but GPS puts the driver at home, not the
        // pickup — the waiting clock must NOT have started.
        $booking = $this->arrived(
            Carbon::parse('2026-08-11 08:30:00'),
            pickup: self::PICKUP,
            pingCoord: self::HOME,
        );

        $this->assertFalse($booking->waitingConfirmedAtPickup());
        $this->assertNull($booking->waitingStartedAt());
        $this->assertSame(0, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_with_no_location_shared_the_timer_never_starts(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        // Arrived 30 min ago but never shared location (no pings at all).
        $booking = $this->arrived(
            Carbon::parse('2026-08-11 08:30:00'),
            pickup: self::PICKUP,
            pingCoord: null,
        );

        $this->assertFalse($booking->waitingConfirmedAtPickup());
        $this->assertSame(0, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_the_clock_starts_when_the_driver_reaches_the_pickup_not_at_the_tap(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        // Tapped Arrived at 09:20 while still driving; only reached the pickup
        // (an in-geofence ping) at 09:40. Waiting is measured from 09:40, so at
        // 10:00 that's 20 min − 15 grace = 5 billable, not 40 − 15.
        $booking = $this->arrived(
            Carbon::parse('2026-08-11 09:20:00'),
            pickup: self::PICKUP,
            pingCoord: self::PICKUP,
            pingAt: Carbon::parse('2026-08-11 09:40:00'),
        );

        $this->assertEquals(Carbon::parse('2026-08-11 09:40:00'), $booking->waitingStartedAt());
        $this->assertSame(5, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_the_driver_screen_shows_the_waiting_timer_when_arrived(): void
    {
        Carbon::setTestNow('2026-08-11 09:20:00');
        $booking = $this->arrived(Carbon::parse('2026-08-11 09:00:00'));

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('waiting-timer')
            ->assertSee('Waiting at pickup')
            ->assertSee('Turn on location')          // the prompt affordance
            ->assertSee('First 15 minutes are free.');

        Carbon::setTestNow();
    }

    public function test_the_waiting_timer_is_hidden_before_the_driver_arrives(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::EnRoute,
        ]);

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertDontSee('waiting-timer');
    }

    public function test_waiting_time_is_frozen_when_the_passenger_boards(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        // At the pickup since 09:38 → 22 min − 15 grace = 7 billable on boarding.
        $booking = $this->arrived(Carbon::parse('2026-08-11 09:38:00'));

        app(BookingStatusService::class)->transition($booking, BookingStatus::Collected, $booking->driver);

        $this->assertSame(7, $booking->fresh()->recordedWaitingMinutes());

        Carbon::setTestNow();
    }

    public function test_boarding_from_home_records_no_waiting_time(): void
    {
        Carbon::setTestNow('2026-08-11 11:00:00');
        // Tapped Arrived at home 30 min ago, then boarded — nothing to charge.
        $booking = $this->arrived(
            Carbon::parse('2026-08-11 10:30:00'),
            pickup: self::PICKUP,
            pingCoord: self::HOME,
        );

        app(BookingStatusService::class)->transition($booking, BookingStatus::Collected, $booking->driver);

        $this->assertNull($booking->fresh()->recordedWaitingMinutes());

        Carbon::setTestNow();
    }

    public function test_without_pickup_coords_it_falls_back_to_the_arrival_tap_when_sharing(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00');
        // Pickup couldn't be geocoded (no coords) but the driver IS sharing
        // location (a ping exists) → best-effort anchor at the tap.
        $booking = $this->arrived(
            Carbon::parse('2026-08-11 11:40:00'),
            pickup: null,
            pingCoord: self::PICKUP,
        );

        $this->assertTrue($booking->waitingConfirmedAtPickup());
        $this->assertSame(5, $booking->waitingBillableMinutes()); // 20 − 15

        Carbon::setTestNow();
    }
}
