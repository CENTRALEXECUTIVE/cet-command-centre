<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Import\EtoAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PickupTimeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithEvent(string $pickup, string $descTime, ?string $slot = null): Booking
    {
        $booking = Booking::factory()->create(['pickup_at' => $pickup]);
        $start = $slot ?? $pickup;
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_x',
            'title' => '*Test MAN (ABDI)*',
            'location' => 'Manchester Airport',
            'description' => "📑 *Booking Confirmation – Departure*\n• *Date & Time:* {$descTime}\n• *Pickup Location:* Somewhere",
            'start_at' => $start,
            'end_at' => Carbon::parse($start)->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent']);
    }

    public function test_booking_page_shows_eto_reference_and_calendar_description(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['external_reference' => 'ETOREF9']);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_desc',
            'title' => '*Test MAN (ESTATE)*',
            'location' => 'Sheffield',
            'description' => "📑 *Booking Confirmation – Departure*\n• *Booking Reference:* ETOREF9\n• *Luggage:* 2 Suitcases + 1 Hand Luggage",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('ETOREF9')                       // the ETO reference is on the booking
            ->assertSee('Full details (from the calendar)')
            ->assertSee('2 Suitcases + 1 Hand Luggage'); // the calendar description is shown
    }

    public function test_luggage_is_mirrored_verbatim_from_the_calendar(): void
    {
        $booking = Booking::factory()->create(['luggage' => 0]); // booking itself says nothing
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_lug',
            'title' => '*Test MAN (ESTATE)*',
            'location' => 'Sheffield',
            'description' => "📑 *Booking Confirmation – Departure*\n• *Luggage:* 2 Suitcases + 1 Hand Luggage\n• *Pickup Location:* Sheffield",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        // The command centre shows exactly what's on the calendar.
        $this->assertEquals('2 Suitcases + 1 Hand Luggage', $booking->fresh()->luggageBreakdown());
        $this->assertEquals('2 Suitcases + 1 Hand Luggage', $booking->fresh()->luggageShort());
    }

    public function test_no_mismatch_when_all_three_times_agree(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00', '15/07/2026 – 06:45');

        $this->assertSame([], $booking->pickupTimeMismatch());
    }

    public function test_mismatch_detected_when_the_description_time_differs(): void
    {
        // Booking + event slot at 06:45, but the description prints 07:45.
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00', '15/07/2026 – 07:45');

        $times = $booking->pickupTimeMismatch();
        $this->assertNotEmpty($times);
        $this->assertArrayHasKey('Calendar description', $times);
        $this->assertArrayHasKey('Booking', $times);
    }

    public function test_dashboard_auto_corrects_a_drifted_booking_time(): void
    {
        $admin = User::factory()->admin()->create();
        $day = now()->addDays(3);
        // The real bug: booking AND the event slot are an hour late (19:45), but
        // the printed description carries the true time (18:45). The description wins.
        $booking = $this->bookingWithEvent(
            $day->format('Y-m-d').' 19:45:00',   // booking 19:45 (wrong)
            $day->format('d/m/Y').' – 18:45',    // description 18:45 (the truth)
            $day->format('Y-m-d').' 19:45:00'    // slot 19:45 (also wrong)
        );

        // Simply opening the dashboard corrects it — no button.
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('corrected to match the calendar');

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertEquals('18:45', $booking->pickup_at->format('H:i'));           // booking fixed
        $this->assertEquals('18:45', $booking->calendarEvent->start_at->format('H:i')); // slot fixed
        $this->assertSame([], $booking->pickupTimeMismatch());                       // and consistent
    }

    public function test_fix_all_snaps_booking_times_to_the_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        // Booking an hour ahead of its calendar slot (the old import drift).
        $day = now()->addDays(2);
        $booking = $this->bookingWithEvent(
            $day->format('Y-m-d').' 19:45:00',              // booking says 19:45 (wrong)
            $day->format('d/m/Y').' – 18:45',               // description 18:45 (the truth)
            $day->format('Y-m-d').' 19:45:00'               // slot 19:45 (also wrong)
        );

        $this->actingAs($admin)->post(route('dashboard.fix-times'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertEquals('18:45', $booking->fresh()->pickup_at->format('H:i'));
        // And the mismatch is now cleared.
        $this->assertSame([], $booking->fresh(['calendarEvent'])->pickupTimeMismatch());
    }

    public function test_only_admins_can_bulk_fix_times(): void
    {
        $driver = User::factory()->create();
        $this->actingAs($driver)->post(route('dashboard.fix-times'))->assertForbidden();
    }

    public function test_audit_flags_a_description_time_that_does_not_match_the_slot(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00', '15/07/2026 – 07:45');

        app(EtoAuditService::class)->search($booking->reference);

        $issues = $booking->fresh()->meta['audit_issues'] ?? [];
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'description time')));
    }
}
