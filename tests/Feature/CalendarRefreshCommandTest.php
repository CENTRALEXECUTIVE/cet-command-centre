<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The scheduled background sync (cet:calendar-refresh) pulls upcoming bookings
 * into line with the live calendar in the shell — so the website never has to
 * reach Google. This is the failsafe when the web process can't talk to Google.
 */
class CalendarRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_and_corrects_upcoming_bookings_from_the_calendar(): void
    {
        // An ETO booking with the wrong stored time and no linked event.
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0),
            'external_reference' => 'JXQN1Ab',
        ]);

        $start = Carbon::parse(now()->addDays(3)->format('Y-m-d').' 16:45:00', 'Europe/London');
        $rawEvent = [
            'id' => 'evt_live',
            'summary' => '*Wayne Bellamy MAN Return (MEHTZ)*',
            'location' => 'Manchester Airport (MAN), Terminal 2',
            'description' => "• *Booking Reference:* JXQN1Ab\n• *Luggage:* 5 Suitcases + 0 Hand Luggage",
            'start' => ['dateTime' => $start->toIso8601String()],
            'end' => ['dateTime' => $start->copy()->addHour()->toIso8601String()],
        ];

        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturnTrue();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('eventsBetween')->andReturn([$rawEvent]);
        $this->instance(GoogleCalendarService::class, $google);

        $this->artisan('cet:calendar-refresh')
            ->assertSuccessful()
            ->expectsOutputToContain('1 corrected');

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertSame('16:45', $booking->pickup_at->format('H:i'));
        $this->assertSame('evt_live', $booking->calendarEvent->google_event_id);
    }

    public function test_it_is_a_no_op_when_the_calendar_is_not_connected(): void
    {
        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturnFalse();
        $google->shouldReceive('active')->andReturnFalse();
        $this->instance(GoogleCalendarService::class, $google);

        $this->artisan('cet:calendar-refresh')
            ->assertSuccessful()
            ->expectsOutputToContain('not connected');
    }
}
