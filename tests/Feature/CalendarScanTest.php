<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The "Scan calendar" button: finds the booking on the LIVE Google Calendar via
 * Google's own search (by the ETO/booking reference), links it, and makes the
 * booking match it exactly. When it can't verify against the live calendar it
 * flags the booking as unverified rather than trusting a possibly-stale copy.
 */
class CalendarScanTest extends TestCase
{
    use RefreshDatabase;

    private function linkedBooking(): Booking
    {
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0),
            'external_reference' => 'JXQN1Ab',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'google_event_id' => 'evt_live',
            'title' => '*Old Name MAN (COVER)*',
            'location' => 'Old address',
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* JXQN1Ab\n• *Date & Time:* 13:00",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent']);
    }

    /**
     * Partial mock: real matching logic runs; only Google IO is stubbed.
     *
     * @param  array|null  $readEvent  what readEvent returns (linked path)
     * @param  array|null  $findResult  what findEventWithDiagnostics returns (unlinked path)
     * @param  array  $events  what eventsBetween returns (bulk path)
     */
    private function mockGoogle(?array $readEvent = null, ?array $findResult = null, array $events = [], bool $connected = true): void
    {
        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturn($connected);
        $google->shouldReceive('active')->andReturn($connected);
        $google->shouldReceive('eventsBetween')->andReturn($events);
        $google->shouldReceive('readEvent')->andReturn($readEvent);
        $google->shouldReceive('findEventWithDiagnostics')->andReturn(
            $findResult ?? ['event' => null, 'diag' => ['read' => true, 'ref_hits' => 0, 'name_hits' => 0, 'reason' => 'no_match']]
        );
        $this->instance(GoogleCalendarService::class, $google);
    }

    /** A normalised live event (as findEvent/readEvent return). */
    private function live(string $time = '16:45'): array
    {
        $start = now()->addDays(3)->setTimeFromTimeString($time.':00');

        return [
            'id' => 'evt_live',
            'start' => Carbon::parse($start),
            'end' => Carbon::parse($start)->addHour(),
            'title' => '*Wayne Bellamy MAN Return (MEHTZ)*',
            'location' => 'Manchester Airport (MAN), Terminal 2',
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* JXQN1Ab\n• *Luggage:* 5 Suitcases + 0 Hand Luggage",
        ];
    }

    /** A raw Google event item (as eventsBetween returns) — timed in UK local,
     *  the way Google actually returns it (e.g. "…T16:45:00+01:00" in summer). */
    private function rawEvent(): array
    {
        $start = Carbon::parse(now()->addDays(3)->format('Y-m-d').' 16:45:00', 'Europe/London');

        return [
            'id' => 'evt_live',
            'summary' => '*Wayne Bellamy MAN Return (MEHTZ)*',
            'location' => 'Manchester Airport (MAN), Terminal 2',
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* JXQN1Ab\n• *Luggage:* 5 Suitcases + 0 Hand Luggage",
            'start' => ['dateTime' => $start->toIso8601String()],
            'end' => ['dateTime' => $start->copy()->addHour()->toIso8601String()],
        ];
    }

    public function test_calendar_time_is_read_as_uk_wall_clock_not_shifted_to_utc(): void
    {
        // A booking scanned against a 16:45 BST calendar event must read 16:45,
        // never 15:45 — the app timezone (UTC in tests) must not shift it.
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0),
            'external_reference' => 'JXQN1Ab',
        ]);
        // Feed a real Google-style value with the London offset through the
        // whole match+normalise path (partial mock; only the HTTP list is faked).
        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturnTrue();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('eventsBetween')->andReturn([$this->rawEvent()]);
        $this->instance(GoogleCalendarService::class, $google);

        $this->actingAs($admin)->post(route('dashboard.fix-times'))->assertRedirect();

        $this->assertSame('16:45', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_scan_corrects_a_linked_booking_to_the_live_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->linkedBooking();
        $this->mockGoogle(readEvent: $this->live('16:45'));

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('scanChanges');

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertSame('16:45', $booking->pickup_at->format('H:i'));
        $this->assertSame('*Wayne Bellamy MAN Return (MEHTZ)*', $booking->calendarEvent->title);
        $this->assertNotEmpty($booking->meta['calendar_scanned_at']);
        $this->assertArrayNotHasKey('calendar_unverified', $booking->meta ?? []);
    }

    public function test_scan_matches_an_eto_booking_with_no_stored_event_id(): void
    {
        // The real-world case: an ETO import with NO calendar event row.
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0), // stale stored time
            'external_reference' => 'JXQN1Ab',
        ]);
        $this->assertNull($booking->calendarEvent);

        $this->mockGoogle(findResult: ['event' => $this->live('16:45'), 'diag' => ['read' => true, 'matched' => 'reference']]);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('scanChanges');

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertNotNull($booking->calendarEvent, 'The booking should now be linked to its calendar event.');
        $this->assertSame('evt_live', $booking->calendarEvent->google_event_id);
        $this->assertSame('16:45', $booking->pickup_at->format('H:i'));
        $this->assertSame('5 Suitcases + 0 Hand Luggage', $booking->luggageBreakdown());
    }

    public function test_scan_reports_a_perfect_match_without_changing_anything(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->linkedBooking();
        $this->mockGoogle(readEvent: [
            'start' => $booking->pickup_at->copy(),
            'end' => $booking->pickup_at->copy()->addHour(),
            'title' => $booking->calendarEvent->title,
            'location' => $booking->calendarEvent->location,
            'description' => $booking->calendarEvent->description,
        ]);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionMissing('scanChanges')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'matches it exactly'));

        $this->assertSame('13:00', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_scan_explains_when_the_calendar_is_not_connected(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->linkedBooking();
        $this->mockGoogle(readEvent: null, connected: false);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'isn’t connected'));

        $this->assertSame('13:00', $booking->fresh()->pickup_at->format('H:i')); // untouched
    }

    public function test_unmatched_booking_is_flagged_unverified_not_silently_trusted(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['external_reference' => 'MISSING1']);
        // Connected, read the calendar, but nothing matched.
        $this->mockGoogle(findResult: ['event' => null, 'diag' => ['read' => true, 'ref_hits' => 0, 'name_hits' => 0]]);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'no matching event'));

        // The booking is flagged so its (unverified) data isn't presented as correct.
        $this->assertNotEmpty($booking->fresh()->meta['calendar_unverified']);
    }

    public function test_dashboard_sync_matches_unlinked_bookings_by_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(9, 0),
            'external_reference' => 'JXQN1Ab',
        ]);
        $this->mockGoogle(events: [$this->rawEvent()]);

        $this->actingAs($admin)
            ->post(route('dashboard.fix-times'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Live calendar sync'));

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertSame('evt_live', $booking->calendarEvent?->google_event_id);
        $this->assertSame('16:45', $booking->pickup_at->format('H:i'));
    }

    public function test_only_admins_can_scan(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->linkedBooking();

        $this->actingAs($driver)->post(route('bookings.scan-calendar', $booking))->assertForbidden();
    }
}
