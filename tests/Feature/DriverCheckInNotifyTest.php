<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\WatchdogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * When a driver checks in ("Getting ready") the office is notified once, and the
 * booking page shows exactly when the check-in opens and when it rings.
 */
class DriverCheckInNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 06:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function job(): Booking
    {
        $driver = User::factory()->driver()->create(['name' => 'Test Driver']);

        return Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addHours(2),
        ]);
    }

    public function test_checking_in_notifies_the_office_once(): void
    {
        $b = $this->job();

        $b->confirmGettingReady($b->driver);
        $this->assertTrue($b->fresh()->gettingReadyConfirmed());
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $b->id,
            'event_type' => 'driver_checked_in',
        ]);
        $this->assertSame(1, WatchdogEvent::where('booking_id', $b->id)->where('event_type', 'driver_checked_in')->count());

        // A second confirmation does not fire a duplicate.
        $b->fresh()->confirmGettingReady($b->driver);
        $this->assertSame(1, WatchdogEvent::where('booking_id', $b->id)->where('event_type', 'driver_checked_in')->count());
    }

    public function test_driver_checked_in_is_a_default_on_alert(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertArrayHasKey('driver_checked_in', User::ALERT_TYPES);
        $this->assertTrue($admin->alertPreferences()['driver_checked_in']);
    }

    public function test_the_booking_page_shows_the_check_in_and_ring_time(): void
    {
        $b = $this->job();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()
            ->assertSee('Check-in opens')
            ->assertSee('if not confirmed', false);
    }

    public function test_the_booking_page_shows_checked_in_once_confirmed(): void
    {
        $b = $this->job();
        $b->confirmGettingReady($b->driver);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()
            ->assertSee('Checked in at')
            ->assertSee('no call will fire', false);
    }
}
