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

    public function test_passenger_count_is_mirrored_from_the_calendar(): void
    {
        // Booking column says 1, but the calendar says 5 passengers — the
        // calendar is the source of truth, so the page shows 5.
        $booking = Booking::factory()->create(['passengers' => 1]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_pax',
            'title' => '*Test MAN (MINIBUS)*',
            'location' => 'Manchester Airport',
            'description' => "📑 *Booking Confirmation – Arrival*\n• *Passengers:* 5\n• *Luggage:* 8 Suitcases + 4 Hand Luggage",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $this->assertSame(5, $booking->fresh(['calendarEvent'])->passengerCount());
    }

    public function test_passenger_count_falls_back_to_the_booking_without_a_calendar(): void
    {
        $booking = Booking::factory()->create(['passengers' => 3]);
        $this->assertSame(3, $booking->passengerCount());
    }

    public function test_every_field_is_mirrored_from_the_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_address' => 'OLD PICKUP', 'destination_address' => 'OLD DROPOFF',
            'flight_number' => 'XX0000', 'passengers' => 1,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_all',
            'title' => '*Emma Cusworth MAN (MINIBUS)*',
            'location' => 'Manchester Airport',
            'description' => implode("\n", [
                '📑 *Booking Confirmation – Arrival*',
                '• *Date & Time:* 24/11/2026 – 07:30',
                '• *Customer Name:* Emma Cusworth',
                '• *Contact No:* +447501028381',
                '• *Passengers:* 5',
                '• *Luggage:* 8 Suitcases + 4 Hand Luggage',
                '• *Pickup Location:* Manchester Airport (MAN), Terminal 2',
                '• *Flight Number:* VS0074',
                '• *Meet & Greet:* Yes',
                '• *Drop-off Location:* 5 Moorbridge Crescent, Barnsley S73 0YA',
                '• *Vehicle Type:* Minibus',
                '• *Payment:* Paid £350 (Stripe)',
                '• *Booking Reference:* DBJ6TRb',
            ]),
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);
        $booking = $booking->fresh(['calendarEvent']);

        // The model mirrors every calendar line, not the stale booking columns.
        $this->assertSame('Emma Cusworth', $booking->displayCustomerName());
        $this->assertSame('+447501028381', $booking->displayContact());
        $this->assertSame(5, $booking->passengerCount());
        $this->assertSame('8 Suitcases + 4 Hand Luggage', $booking->luggageBreakdown());
        $this->assertSame('Manchester Airport (MAN), Terminal 2', $booking->displayPickupAddress());
        $this->assertSame('VS0074', $booking->displayFlightNumber());
        $this->assertSame('Yes', $booking->displayMeetAndGreet());
        $this->assertSame('5 Moorbridge Crescent, Barnsley S73 0YA', $booking->displayDropoffAddress());
        $this->assertSame('Minibus', $booking->displayVehicleType());
        $this->assertSame('Paid £350 (Stripe)', $booking->displayPayment());

        // And the booking page renders the calendar's values, not the old ones.
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Emma Cusworth')
            ->assertSee('Manchester Airport (MAN), Terminal 2')
            ->assertSee('Minibus')
            ->assertSee('Paid £350 (Stripe)')
            ->assertDontSee('OLD PICKUP')
            ->assertDontSee('OLD DROPOFF');
    }

    public function test_display_fields_fall_back_to_the_booking_without_a_calendar(): void
    {
        $booking = Booking::factory()->create([
            'pickup_address' => 'Sheffield City Centre',
            'destination_address' => 'Leeds Bradford Airport',
        ]);

        $this->assertSame('Sheffield City Centre', $booking->displayPickupAddress());
        $this->assertSame('Leeds Bradford Airport', $booking->displayDropoffAddress());
        $this->assertNull($booking->displayPayment());          // no calendar → structured payment shown
        $this->assertNull($booking->displayMeetAndGreet());
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

    public function test_pages_do_not_silently_revert_a_booking_time(): void
    {
        // A booking whose time differs from our stored calendar copy must NOT be
        // auto-reverted just by viewing it — otherwise the operator can never fix
        // a time in the app (e.g. after editing the live Google event).
        $admin = User::factory()->admin()->create();
        $day = now()->addDays(3);
        $booking = $this->bookingWithEvent(
            $day->format('Y-m-d').' 15:00:00',   // booking (operator-set) 15:00
            $day->format('d/m/Y').' – 13:00',    // stored description 13:00 (stale)
            $day->format('Y-m-d').' 13:00:00'    // stored slot 13:00 (stale)
        );

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk();
        $this->actingAs($admin)->get(route('despatch.board', ['date' => $day->toDateString()]))->assertOk();
        $this->actingAs($admin)->get(route('bookings.index'))->assertOk();

        // Untouched by every page load — the operator's 15:00 stands.
        $this->assertEquals('15:00', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_booking_page_follows_the_live_calendar_when_connected(): void
    {
        $admin = User::factory()->admin()->create();
        $day = now()->addDays(4);
        // Booking + stored copy say 15:00, but the LIVE Google event now says 17:30.
        $booking = $this->bookingWithEvent(
            $day->format('Y-m-d').' 15:00:00',
            $day->format('d/m/Y').' – 15:00',
            $day->format('Y-m-d').' 15:00:00'
        );
        $liveStart = $day->copy()->setTime(17, 30);

        // Pretend Google Calendar is connected and the live event reads 17:30.
        // The scan is reference-first, so no-match there falls back to reading
        // the stored event id — which returns the live 17:30.
        $google = \Mockery::mock(\App\Services\Calendar\GoogleCalendarService::class);
        $google->shouldReceive('configured')->andReturnTrue();
        $google->shouldReceive('active')->andReturnTrue();
        $google->shouldReceive('findEventWithDiagnostics')->andReturn([
            'event' => null, 'diag' => ['read' => true, 'ref_hits' => 0, 'name_hits' => 0],
        ]);
        $google->shouldReceive('readEvent')->andReturn([
            'start' => $liveStart->copy(),
            'description' => '',
        ]);
        $this->instance(\App\Services\Calendar\GoogleCalendarService::class, $google);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk();

        // The page silently matched the live calendar — 17:30 now shows.
        $this->assertEquals('17:30', $booking->fresh()->pickup_at->format('H:i'));
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

    public function test_correcting_a_time_clears_the_stale_time_warning(): void
    {
        $admin = User::factory()->admin()->create();
        $day = now()->addDays(2);
        $booking = $this->bookingWithEvent(
            $day->format('Y-m-d').' 19:45:00',
            $day->format('d/m/Y').' – 18:45',
            $day->format('Y-m-d').' 19:45:00'
        );
        // A stale time warning plus an unrelated one already stored on the booking.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'audit_issues' => ["Calendar time (19:45) doesn't match the booking (18:45)", 'Drop-off address is missing/unknown'],
        ])])->save();

        $this->actingAs($admin)->post(route('dashboard.fix-times'))->assertRedirect();

        $issues = $booking->fresh()->meta['audit_issues'];
        $this->assertNotContains("Calendar time (19:45) doesn't match the booking (18:45)", $issues); // time warning gone
        $this->assertContains('Drop-off address is missing/unknown', $issues);                        // real one kept
    }

    public function test_only_admins_can_bulk_fix_times(): void
    {
        $driver = User::factory()->create();
        $this->actingAs($driver)->post(route('dashboard.fix-times'))->assertForbidden();
    }

    public function test_audit_corrects_a_description_vs_slot_disagreement(): void
    {
        // Slot 06:45 but the printed description says 07:45 — the description wins,
        // and the audit brings the booking + slot to it, leaving no time warning.
        $booking = $this->bookingWithEvent('2026-07-15 06:45:00', '15/07/2026 – 07:45');

        app(EtoAuditService::class)->search($booking->reference);

        $booking = $booking->fresh(['calendarEvent']);
        $this->assertEquals('07:45', $booking->pickup_at->format('H:i'));
        $this->assertEquals('07:45', $booking->calendarEvent->start_at->format('H:i'));
        $issues = $booking->meta['audit_issues'] ?? [];
        $this->assertStringNotContainsString('description time', implode(' ', $issues));
    }
}
