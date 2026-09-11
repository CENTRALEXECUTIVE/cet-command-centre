<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Services\Calendar\CalendarTimeSync;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A manual edit made in CET must STICK — the automatic calendar refresh must never
 * revert it back to the calendar original. Only the "Match calendar" button does.
 * Also: the driver's Waze/Maps links navigate to exact coordinates when we have
 * them, so a same-named place (a different "Whitby's" branch) can't hijack them.
 */
class EditStickinessTest extends TestCase
{
    use RefreshDatabase;

    private function linkedBooking(string $pickupInDescription): Booking
    {
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0),
            'external_reference' => 'JXQN1Ab',
            'pickup_address' => $pickupInDescription,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'google_event_id' => 'evt_live',
            'title' => '*Karl Burke DON (COVER)*',
            'location' => $pickupInDescription,
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* JXQN1Ab\n• *Pickup Location:* {$pickupInDescription}\n• *Date & Time:* 13:00",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent']);
    }

    private function mockLiveCalendar(string $pickupOnCalendar): void
    {
        $live = [
            'id' => 'evt_live',
            'start' => Carbon::parse(now()->addDays(3)->setTime(13, 0)),
            'end' => Carbon::parse(now()->addDays(3)->setTime(14, 0)),
            'title' => '*Karl Burke DON (COVER)*',
            'location' => $pickupOnCalendar,
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* JXQN1Ab\n• *Pickup Location:* {$pickupOnCalendar}\n• *Date & Time:* 13:00",
        ];
        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturnTrue();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('readEvent')->andReturn($live);
        $google->shouldReceive('findEventWithDiagnostics')->andReturn(['event' => $live, 'diag' => ['read' => true, 'matched' => 'reference']]);
        $this->instance(GoogleCalendarService::class, $google);
    }

    public function test_the_auto_scan_freezes_the_local_calendar_copy_of_an_edited_booking(): void
    {
        // The local copy holds the edited pickup; the calendar still says the
        // original. The automatic refresh must NOT pull the original back in.
        $booking = $this->linkedBooking("Whitby's Fish & Chip Restaurant Doncaster, Leicester Avenue, Doncaster");
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'manually_edited_at' => now()->toIso8601String(),
            'edited_fields' => ['pickup_address'],
        ])])->save();

        $this->mockLiveCalendar('Doncaster Racecourse, Bawtry Road, Doncaster');
        app(CalendarTimeSync::class)->scan($booking->fresh(['calendarEvent']));

        $booking->refresh()->load('calendarEvent');
        // Frozen: the local copy still shows the edited pickup, not the calendar's.
        $this->assertStringContainsString("Whitby's", (string) $booking->calendarEvent->location);
        $this->assertStringContainsString("Whitby's", (string) $booking->calendarEvent->description);
        $this->assertStringNotContainsString('Racecourse', (string) $booking->calendarEvent->description);
    }

    public function test_a_non_edited_booking_still_mirrors_the_calendar(): void
    {
        // No manual edit → the auto-scan keeps mirroring the live calendar.
        $booking = $this->linkedBooking('Old address');
        $this->mockLiveCalendar('Doncaster Racecourse, Bawtry Road, Doncaster');

        app(CalendarTimeSync::class)->scan($booking);

        $booking->refresh()->load('calendarEvent');
        $this->assertStringContainsString('Racecourse', (string) $booking->calendarEvent->location);
    }

    public function test_an_edited_pickup_field_shows_the_edited_value_over_the_calendar(): void
    {
        // Per-field: displayPickupAddress prefers the office's edited value even
        // though the calendar description still holds the original.
        $booking = $this->linkedBooking('Doncaster Racecourse, Bawtry Road, Doncaster');
        $booking->forceFill([
            'pickup_address' => "Whitby's Fish & Chip Restaurant Doncaster, Leicester Avenue, Doncaster",
            'meta' => array_merge($booking->meta ?? [], [
                'manually_edited_at' => now()->toIso8601String(),
                'edited_fields' => ['pickup_address'],
            ]),
        ])->save();

        $this->assertStringContainsString("Whitby's", (string) $booking->fresh(['calendarEvent'])->displayPickupAddress());
    }

    /* ── Waze / Maps use coordinates ─────────────────────────────────────── */

    public function test_waze_and_maps_use_coordinates_when_we_have_them(): void
    {
        $b = Booking::factory()->create([
            'pickup_address' => "Whitby's Fish & Chip Restaurant Doncaster",
            'meta' => ['geo' => ['pickup' => [53.5228, -1.1285]]],
        ]);

        $this->assertStringContainsString('ll=53.5228,-1.1285', $b->wazeUrl('pickup'));
        $this->assertStringContainsString('destination=53.5228%2C-1.1285', $b->mapsUrl('pickup'));
    }

    public function test_waze_falls_back_to_an_address_search_without_coordinates(): void
    {
        $b = Booking::factory()->create([
            'pickup_address' => "Whitby's Fish & Chip Restaurant Doncaster",
            'meta' => [],
        ]);

        $url = $b->wazeUrl('pickup');
        $this->assertStringContainsString('q=', $url);
        $this->assertStringContainsString('Whitby', rawurldecode($url));
    }
}
