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
 * Waiting time: once the driver has ARRIVED, the customer gets a free grace
 * period; only after it does billable waiting time start. Purely a driver-screen
 * timer plus a figure frozen for the office — no messages are sent.
 */
class WaitingTimeTest extends TestCase
{
    use RefreshDatabase;

    private function arrivedBooking(Carbon $arrivedAt): Booking
    {
        $driver = User::factory()->driver()->create(['name' => 'Wait Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Arrived,
        ]);
        $booking->statusHistory()->create([
            'from_status' => BookingStatus::EnRoute->value,
            'to_status' => BookingStatus::Arrived->value,
            'changed_by' => $driver->id,
            'created_at' => $arrivedAt,
        ]);

        return $booking->fresh();
    }

    public function test_no_billable_time_during_the_grace_period(): void
    {
        Carbon::setTestNow('2026-08-11 08:10:00');
        // Arrived 8 minutes ago — inside the free 15-minute grace.
        $booking = $this->arrivedBooking(Carbon::parse('2026-08-11 08:02:00'));

        $this->assertNotNull($booking->arrivedAt());
        $this->assertSame(0, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_billable_time_counts_only_past_the_grace(): void
    {
        Carbon::setTestNow('2026-08-11 08:30:00');
        // Arrived 25 minutes ago → 25 − 15 grace = 10 billable minutes.
        $booking = $this->arrivedBooking(Carbon::parse('2026-08-11 08:05:00'));

        $this->assertSame(10, $booking->waitingBillableMinutes());

        Carbon::setTestNow();
    }

    public function test_the_driver_screen_shows_the_waiting_timer_when_arrived(): void
    {
        Carbon::setTestNow('2026-08-11 09:20:00');
        $booking = $this->arrivedBooking(Carbon::parse('2026-08-11 09:00:00'));

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('waiting-timer')          // the live timer element
            ->assertSee('Waiting at pickup')
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
        $driver = User::factory()->driver()->create(['name' => 'Board Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Arrived,
        ]);
        // Arrived 22 minutes ago → 7 billable minutes when they board now.
        $booking->statusHistory()->create([
            'from_status' => BookingStatus::EnRoute->value,
            'to_status' => BookingStatus::Arrived->value,
            'changed_by' => $driver->id,
            'created_at' => Carbon::parse('2026-08-11 09:38:00'),
        ]);

        app(BookingStatusService::class)->transition($booking->fresh(), BookingStatus::Collected, $driver);

        $this->assertSame(7, $booking->fresh()->recordedWaitingMinutes());

        Carbon::setTestNow();
    }

    public function test_boarding_within_the_grace_records_no_waiting_time(): void
    {
        Carbon::setTestNow('2026-08-11 11:00:00');
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Arrived,
        ]);
        // Arrived only 5 minutes ago → still free, nothing billable.
        $booking->statusHistory()->create([
            'from_status' => BookingStatus::EnRoute->value,
            'to_status' => BookingStatus::Arrived->value,
            'changed_by' => $driver->id,
            'created_at' => Carbon::parse('2026-08-11 10:55:00'),
        ]);

        app(BookingStatusService::class)->transition($booking->fresh(), BookingStatus::Collected, $driver);

        $this->assertSame(0, $booking->fresh()->recordedWaitingMinutes());

        Carbon::setTestNow();
    }
}
