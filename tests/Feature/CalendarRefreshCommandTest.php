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

    public function test_it_imports_a_booking_added_straight_onto_the_calendar(): void
    {
        $this->seed(\Database\Seeders\VehicleTypeSeeder::class);

        // The operator created this event directly on Google Calendar — there
        // is NO booking in the system for it.
        $start = Carbon::parse(now()->addDays(2)->format('Y-m-d').' 09:30:00', 'Europe/London');
        $rawEvent = [
            'id' => 'evt_new_on_calendar',
            'summary' => '*Louise McLaughlin EMA Return (MAJ)*',
            'location' => 'East Midlands Airport (EMA)',
            'description' => implode("\n", [
                '📑 *Booking Confirmation – Departure*',
                '• *Customer Name:* Louise McLaughlin',
                '• *Contact No:* +447700900222',
                '• *Passengers:* 2',
                '• *Luggage:* 2 Suitcases + 1 Hand Luggage',
                '• *Pickup Location:* Sheffield S7',
                '• *Drop-off Location:* East Midlands Airport (EMA)',
                '• *Vehicle Type:* Executive',
                '• *Payment:* Paid £95 (Stripe)',
                '• *Booking Reference:* NEWCAL1',
            ]),
            'start' => ['dateTime' => $start->toIso8601String()],
            'end' => ['dateTime' => $start->copy()->addHour()->toIso8601String()],
        ];

        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturnTrue();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('eventsBetween')->andReturn([$rawEvent]);
        $this->instance(GoogleCalendarService::class, $google);
        // CalendarStats fetches its own event list — stub it out of the way.
        $stats = \Mockery::mock(\App\Services\Calendar\CalendarStats::class)->makePartial();
        $this->instance(\App\Services\Calendar\CalendarStats::class, $stats);

        $this->artisan('cet:calendar-refresh')
            ->assertSuccessful()
            ->expectsOutputToContain('Imported 1 new booking(s)');

        $booking = Booking::where('external_reference', 'NEWCAL1')->with('calendarEvent', 'customer')->first();
        $this->assertNotNull($booking, 'The calendar-only job must become a booking.');
        $this->assertSame('09:30', $booking->pickup_at->format('H:i'));
        $this->assertSame('Louise McLaughlin', $booking->customer->name);
        $this->assertSame('evt_new_on_calendar', $booking->calendarEvent->google_event_id);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame(95.0, (float) $booking->quoted_price);

        // Run again — no duplicate is created.
        $this->artisan('cet:calendar-refresh')->assertSuccessful();
        $this->assertSame(1, Booking::where('external_reference', 'NEWCAL1')->count());
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
