<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Scan calendar" button: finds the booking on the LIVE Google Calendar —
 * by the ETO/booking reference in the event description even when we never
 * stored an event id — links it, and makes the booking match it exactly
 * (time, title, location, details). Read-only on Google.
 */
class CalendarScanTest extends TestCase
{
    use RefreshDatabase;

    private function linkedBooking(): Booking
    {
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0),
            'external_reference' => 'XKR4HK',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'google_event_id' => 'evt_live',
            'title' => '*Old Name MAN (ABDI)*',
            'location' => 'Old address',
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* XKR4HK\n• *Date & Time:* 13:00",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent']);
    }

    /** A partial mock so the real matching logic runs; only IO is stubbed. */
    private function mockGoogle(?array $readEvent = null, array $events = [], bool $connected = true): void
    {
        $google = \Mockery::mock(GoogleCalendarService::class)->makePartial();
        $google->shouldReceive('configured')->andReturn($connected);
        $google->shouldReceive('active')->andReturn($connected);
        $google->shouldReceive('eventsBetween')->andReturn($events);
        if ($readEvent !== null) {
            $google->shouldReceive('readEvent')->andReturn($readEvent);
        } else {
            $google->shouldReceive('readEvent')->andReturnNull();
        }
        $this->instance(GoogleCalendarService::class, $google);
    }

    /** A raw Google event item (as the API returns it). */
    private function rawEvent(array $overrides = []): array
    {
        $start = now()->addDays(3)->setTime(16, 50);

        return array_merge([
            'id' => 'evt_live',
            'summary' => '*Caldic UK Ltd MAN (MAJ)*',
            'location' => 'Casa Hotel, Lockoford Lane, Chesterfield',
            'description' => "📑 *Booking Confirmation*\n• *Booking Reference:* XKR4HK\n• *Luggage:* 2 Suitcases + 3 Hand Luggage",
            'start' => ['dateTime' => $start->toIso8601String()],
            'end' => ['dateTime' => $start->copy()->addHour()->toIso8601String()],
        ], $overrides);
    }

    public function test_scan_corrects_a_linked_booking_to_the_live_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->linkedBooking();
        $liveStart = now()->addDays(3)->setTime(16, 50, 0);

        $this->mockGoogle(readEvent: [
            'start' => \Illuminate\Support\Carbon::parse($liveStart),
            'end' => \Illuminate\Support\Carbon::parse($liveStart)->addHour(),
            'title' => '*Caldic UK Ltd MAN (MAJ)*',
            'location' => 'Casa Hotel, Lockoford Lane, Chesterfield',
            'description' => "📑 *Booking Confirmation*\n• *Luggage:* 2 Suitcases + 3 Hand Luggage",
        ]);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('scanChanges');

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertSame('16:50', $booking->pickup_at->format('H:i'));
        $this->assertSame('*Caldic UK Ltd MAN (MAJ)*', $booking->calendarEvent->title);
        $this->assertSame('Casa Hotel, Lockoford Lane, Chesterfield', $booking->calendarEvent->location);
        $this->assertStringContainsString('2 Suitcases + 3 Hand Luggage', $booking->calendarEvent->description);
        $this->assertNotEmpty($booking->meta['calendar_scanned_at']);
    }

    public function test_scan_matches_an_eto_booking_with_no_stored_event_id(): void
    {
        // The real-world XKR4HK case: an ETO import with NO calendar event row.
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(15, 50), // wrong stored time
            'external_reference' => 'XKR4HK',
        ]);
        $this->assertNull($booking->calendarEvent);

        // The live calendar has the event (found by reference in its description).
        $this->mockGoogle(events: [$this->rawEvent()]);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('scanChanges');

        $booking = $booking->fresh(['calendarEvent']);
        // It is now linked and matches the live event exactly.
        $this->assertNotNull($booking->calendarEvent, 'The booking should now be linked to its calendar event.');
        $this->assertSame('evt_live', $booking->calendarEvent->google_event_id);
        $this->assertSame('16:50', $booking->pickup_at->format('H:i'));
        $this->assertStringContainsString('2 Suitcases + 3 Hand Luggage', $booking->calendarEvent->description);
        // And the live description now drives the displayed luggage.
        $this->assertSame('2 Suitcases + 3 Hand Luggage', $booking->luggageBreakdown());
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
        $this->mockGoogle(connected: false);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'isn’t connected'));

        $this->assertSame('13:00', $booking->fresh()->pickup_at->format('H:i')); // untouched
    }

    public function test_scan_explains_when_the_event_is_not_found_on_the_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['external_reference' => 'MISSING1']);
        $this->mockGoogle(events: []); // connected, but nothing matches

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Couldn’t find this booking'));
    }

    public function test_dashboard_sync_matches_unlinked_bookings_by_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(9, 0),
            'external_reference' => 'XKR4HK',
        ]);

        $this->mockGoogle(events: [$this->rawEvent()]);

        $this->actingAs($admin)
            ->post(route('dashboard.fix-times'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Live calendar sync'));

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertSame('evt_live', $booking->calendarEvent?->google_event_id);
        $this->assertSame('16:50', $booking->pickup_at->format('H:i'));
    }

    public function test_only_admins_can_scan(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->linkedBooking();

        $this->actingAs($driver)->post(route('bookings.scan-calendar', $booking))->assertForbidden();
    }
}
