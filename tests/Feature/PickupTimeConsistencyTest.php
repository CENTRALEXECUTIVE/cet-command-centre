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

    public function test_dashboard_notifies_the_office_of_a_time_mismatch(): void
    {
        $admin = User::factory()->admin()->create();
        $day = now()->addDays(3);
        $this->bookingWithEvent($day->format('Y-m-d').' 06:45:00', $day->format('d/m/Y').' – 07:45');

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('pickup-time mismatch');
    }

    public function test_audit_flags_a_description_time_that_does_not_match_the_slot(): void
    {
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00', '15/07/2026 – 07:45');

        app(EtoAuditService::class)->search($booking->reference);

        $issues = $booking->fresh()->meta['audit_issues'] ?? [];
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'description time')));
    }
}
