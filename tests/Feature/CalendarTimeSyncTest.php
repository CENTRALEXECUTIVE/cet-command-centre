<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Calendar\CalendarTimeSync;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarTimeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithEvent(string $pickup): Booking
    {
        $booking = Booking::factory()->create(['pickup_at' => $pickup]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_time',
            'title' => '*Test MAN (ABDI)*',
            'location' => 'Manchester Airport',
            'description' => '📑 Booking Confirmation',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent']);
    }

    /** @param array{start: Carbon, description: ?string}|null $live */
    private function syncWithLiveEvent(?array $live): CalendarTimeSync
    {
        $google = \Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('readEvent')->andReturn($live);

        return new CalendarTimeSync($google);
    }

    public function test_pull_time_moves_the_booking_to_the_live_calendar_time(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 07:45:00'); // wrong (an hour late)
        $calendarTime = Carbon::parse('2026-07-15 06:45:00'); // the correct time on the calendar

        $result = $this->syncWithLiveEvent(['start' => $calendarTime, 'description' => null])->pullTime($booking);

        $this->assertEquals('updated', $result['status']);
        $this->assertEquals('06:45', $booking->fresh()->pickup_at->format('H:i'));
        // The local event copy is kept in step so the audit doesn't then flag it.
        $this->assertEquals('06:45', $booking->fresh()->calendarEvent->start_at->format('H:i'));
        $this->assertEquals('07:45', $booking->fresh()->calendarEvent->end_at->format('H:i'));
    }

    public function test_pull_time_reports_a_match_when_already_equal(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00');
        $calendarTime = Carbon::parse('2026-07-15 06:45:00');

        $result = $this->syncWithLiveEvent(['start' => $calendarTime, 'description' => null])->pullTime($booking);

        $this->assertEquals('matches', $result['status']);
        $this->assertEquals('06:45', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_pull_time_is_unavailable_when_the_calendar_cannot_be_read(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00');

        $result = $this->syncWithLiveEvent(null)->pullTime($booking);

        $this->assertEquals('unavailable', $result['status']);
        $this->assertEquals('06:45', $booking->fresh()->pickup_at->format('H:i')); // untouched
    }

    public function test_admin_can_trigger_sync_but_non_admin_cannot(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create();

        // Calendar isn't configured in tests → a safe "unavailable" message, no error.
        $this->actingAs($admin)->post(route('bookings.sync-time', $booking))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->actingAs($driver)->post(route('bookings.sync-time', $booking))->assertForbidden();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
