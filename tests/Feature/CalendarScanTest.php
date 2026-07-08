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
 * The "Scan calendar" button: reads the LIVE Google event and makes the
 * booking match it exactly — time, title, location, details — reporting every
 * correction. Read-only on Google.
 */
class CalendarScanTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithStaleCopy(): Booking
    {
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(3)->setTime(13, 0),
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_live',
            'title' => '*Old Name MAN (ABDI)*',
            'location' => 'Old address',
            'description' => "📑 *Booking Confirmation – Departure*\n• *Date & Time:* ".now()->addDays(3)->format('d/m/Y').' – 13:00',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent']);
    }

    private function mockLive(?array $payload): void
    {
        $google = \Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('configured')->andReturnTrue();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('readEvent')->andReturn($payload);
        $this->instance(GoogleCalendarService::class, $google);
    }

    public function test_scan_corrects_everything_to_the_live_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingWithStaleCopy();
        $liveStart = now()->addDays(3)->setTime(16, 50, 0);

        $this->mockLive([
            'start' => Carbon::parse($liveStart),
            'end' => Carbon::parse($liveStart)->addHour(),
            'title' => '*Caldic UK Ltd MAN (MAJ)*',
            'location' => 'Casa Hotel, Lockoford Lane, Chesterfield',
            'description' => "📑 *Booking Confirmation – Departure*\n• *Date & Time:* ".$liveStart->format('d/m/Y').' – 16:50'."\n• *Luggage:* 2 Suitcases + 3 Hand Luggage",
        ]);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('scanChanges');

        $booking = $booking->fresh(['calendarEvent']);
        // Booking time, our event copy, and the details all now match the LIVE event.
        $this->assertSame('16:50', $booking->pickup_at->format('H:i'));
        $this->assertSame('*Caldic UK Ltd MAN (MAJ)*', $booking->calendarEvent->title);
        $this->assertSame('Casa Hotel, Lockoford Lane, Chesterfield', $booking->calendarEvent->location);
        $this->assertSame('16:50', $booking->calendarEvent->start_at->format('H:i'));
        $this->assertStringContainsString('2 Suitcases + 3 Hand Luggage', $booking->calendarEvent->description);
        // The scan is stamped so the page can show "Last scanned …".
        $this->assertNotEmpty($booking->meta['calendar_scanned_at']);
        // And the mirrored description now drives the displayed luggage.
        $this->assertSame('2 Suitcases + 3 Hand Luggage', $booking->luggageBreakdown());
    }

    public function test_scan_reports_a_perfect_match_without_changing_anything(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingWithStaleCopy();

        $this->mockLive([
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

    public function test_scan_explains_when_the_calendar_cannot_be_read(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingWithStaleCopy();

        $google = \Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('configured')->andReturnFalse();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('readEvent')->andReturnNull();
        $this->instance(GoogleCalendarService::class, $google);

        $this->actingAs($admin)
            ->post(route('bookings.scan-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'isn’t connected'));

        $this->assertSame('13:00', $booking->fresh()->pickup_at->format('H:i')); // untouched
    }

    public function test_dashboard_sync_scans_upcoming_bookings_against_the_live_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingWithStaleCopy();
        $liveStart = now()->addDays(3)->setTime(9, 15, 0);

        $this->mockLive([
            'start' => Carbon::parse($liveStart),
            'end' => Carbon::parse($liveStart)->addHour(),
            'title' => $booking->calendarEvent->title,
            'location' => $booking->calendarEvent->location,
            'description' => $booking->calendarEvent->description,
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.fix-times'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Live calendar sync'));

        $this->assertSame('09:15', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_only_admins_can_scan(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->bookingWithStaleCopy();

        $this->actingAs($driver)->post(route('bookings.scan-calendar', $booking))->assertForbidden();
    }
}
