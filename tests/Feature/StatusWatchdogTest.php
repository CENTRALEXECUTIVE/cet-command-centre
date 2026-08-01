<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\JobNudge;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\Watchdog\StatusWatchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 1 gate: every nudge rule, lead-time clamping, max-2 sends, GPS jitter
 * resistance, stale-GPS fallback, jobs without coords, the BST/midnight
 * boundary, and command idempotency.
 */
class StatusWatchdogTest extends TestCase
{
    use RefreshDatabase;

    /** Sheffield-ish fixed points for geofence tests. */
    private const PICKUP = [53.400000, -1.500000];

    private const DROPOFF = [53.358800, -2.272700]; // Manchester Airport-ish

    protected function setUp(): void
    {
        parent::setUp();
        // A stable daytime clock — every test controls time explicitly.
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function driver(): User
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        return $driver;
    }

    private function job(BookingStatus $status, Carbon $pickup, ?array $geo = null): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $this->driver()->id,
            'status' => $status,
            'pickup_at' => $pickup,
            'meta' => $geo ? ['geo' => $geo] : null,
        ]);
    }

    private function ping(Booking $b, float $lat, float $lng, Carbon $at, ?float $speedMs = null): DriverLocation
    {
        return DriverLocation::create([
            'driver_id' => $b->driver_id, 'booking_id' => $b->id,
            'latitude' => $lat, 'longitude' => $lng, 'speed' => $speedMs,
            'captured_at' => $at,
        ]);
    }

    private function tick(): void
    {
        $this->artisan('cet:status-watchdog')->assertSuccessful();
    }

    private function assertNudged(Booking $b, string $type, int $times = 1): void
    {
        $this->assertSame($times, JobNudge::where('booking_id', $b->id)->where('nudge_type', $type)->count(),
            "Expected {$times} '{$type}' nudge(s)");
    }

    /* ── Rules 1 + 2: set off ─────────────────────────────────────────────── */

    public function test_set_off_reminder_uses_flat_30_minutes_without_gps(): void
    {
        // No GPS history → lead time is a flat 30 min. Pickup in 28 → nudge.
        $due = $this->job(BookingStatus::Allocated, now()->addMinutes(28));
        // Pickup in 35 → outside the flat window → nothing yet.
        $notYet = $this->job(BookingStatus::Allocated, now()->addMinutes(35));

        $this->tick();

        $this->assertNudged($due, 'set_off');
        $this->assertNudged($notYet, 'set_off', 0);
        // The nudge is also on the alerts feed.
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $due->id, 'event_type' => 'nudge_set_off']);
    }

    public function test_past_allocated_jobs_are_left_alone(): void
    {
        // A job whose pickup was hours ago and never got moving — still Allocated
        // because the office marks it complete off the calendar. It must NOT fire
        // any set-off nudge or admin escalation (only present/future jobs do).
        $past = $this->job(BookingStatus::Allocated, now()->subHours(3));

        $this->tick();

        $this->assertNudged($past, 'set_off', 0);
        $this->assertNudged($past, 'set_off_urgent', 0);
        $this->assertSame(0, JobNudge::where('booking_id', $past->id)->where('recipient_type', 'admin')->count());
    }

    public function test_an_unallocated_past_pickup_does_not_escalate(): void
    {
        // Pending + no driver, pickup already gone → no "unallocated" siren.
        $past = Booking::factory()->create([
            'driver_id' => null, 'status' => BookingStatus::Pending, 'pickup_at' => now()->subHours(2),
        ]);

        $this->tick();

        $this->assertSame(0, JobNudge::where('booking_id', $past->id)->count());
    }

    public function test_set_off_reminder_also_covers_accepted_jobs(): void
    {
        // This app has an Accepted step between Allocated and En Route — a
        // driver who accepted but hasn't set off still needs the reminder.
        $b = $this->job(BookingStatus::Accepted, now()->addMinutes(25));

        $this->tick();

        $this->assertNudged($b, 'set_off');
    }

    public function test_lead_time_is_drive_plus_five_clamped_between_15_and_150(): void
    {
        $watchdog = app(StatusWatchdog::class);

        // Driver very far away (London → hours of driving) → clamped DOWN to 150.
        $far = $this->job(BookingStatus::Allocated, now()->addHours(6), ['pickup' => self::PICKUP]);
        $this->ping($far, 51.5074, -0.1278, now()->subMinutes(5));
        $this->assertSame(150, $watchdog->setOffLeadMinutes($far->fresh()));

        // Driver already next door (couple of streets) → clamped UP to 15.
        $near = $this->job(BookingStatus::Allocated, now()->addHours(3), ['pickup' => self::PICKUP]);
        $this->ping($near, 53.4010, -1.5010, now()->subMinutes(5));
        $this->assertSame(15, $watchdog->setOffLeadMinutes($near->fresh()));
    }

    public function test_airport_pickup_without_gps_leads_by_the_route_length(): void
    {
        // No live position + an airport pickup: the driver still has to DRIVE to
        // the airport, roughly the fare's own route length — so the lead is that,
        // not a flat 30. A ~1h30 route → set off ~95 min before, not 30.
        $watchdog = app(StatusWatchdog::class);
        $b = $this->job(BookingStatus::Allocated, now()->addHours(3), [
            'pickup' => self::PICKUP, 'dropoff' => self::DROPOFF,
        ]);
        $b->forceFill(['pickup_address' => 'Manchester Airport (MAN), Terminal 2'])->save();

        $route = $watchdog->estimatedDurationMinutes($b->fresh());
        $this->assertSame(max(30, min(150, $route + 5)), $watchdog->setOffLeadMinutes($b->fresh()));
        $this->assertGreaterThan(30, $watchdog->setOffLeadMinutes($b->fresh()));
    }

    public function test_complete_by_is_anchored_to_when_the_customer_boarded(): void
    {
        $watchdog = app(StatusWatchdog::class);
        $b = $this->job(BookingStatus::Collected, now()->subHour(), [
            'pickup' => self::PICKUP, 'dropoff' => self::DROPOFF,
        ]);
        // Customer got in the car 20 minutes ago (a long airport wait beforehand
        // no longer matters — the clock starts from POB).
        $pob = now()->subMinutes(20);
        $b->statusHistory()->create(['from_status' => 'arrived', 'to_status' => 'collected', 'created_at' => $pob]);

        $drive = $watchdog->estimatedDurationMinutes($b->fresh());
        $this->assertEqualsWithDelta(
            $pob->copy()->addMinutes($drive + 30)->timestamp,
            $watchdog->completeBy($b->fresh())->timestamp, 2);
    }

    public function test_airport_pickup_without_pob_gets_a_two_hour_window(): void
    {
        $watchdog = app(StatusWatchdog::class);
        $b = $this->job(BookingStatus::Allocated, now()->addHour(), [
            'pickup' => self::PICKUP, 'dropoff' => self::DROPOFF,
        ]);
        $b->forceFill([
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'meta' => array_merge($b->meta ?? [], ['journey_label' => 'Arrival']),
        ])->save();

        $drive = $watchdog->estimatedDurationMinutes($b->fresh());
        // Landing → clear the terminal (2h) → drive, all from the scheduled pickup.
        $this->assertEqualsWithDelta(
            $b->pickup_at->copy()->addMinutes(120 + $drive)->timestamp,
            $watchdog->completeBy($b->fresh())->timestamp, 2);
    }

    public function test_airport_pickup_set_off_deadline_is_driven_by_the_flight_landing(): void
    {
        $watchdog = app(StatusWatchdog::class);

        // Arrival at Manchester Airport → home in Sheffield. No live driver GPS,
        // so the drive is estimated from the job's own route (airport→home ≈
        // home→airport).
        $b = $this->job(BookingStatus::Allocated, now()->addHours(4), [
            'pickup' => self::DROPOFF,  // airport coords
            'dropoff' => self::PICKUP,  // home coords
        ]);
        $b->forceFill([
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'meta' => array_merge($b->meta ?? [], ['journey_label' => 'Arrival']),
        ])->save();

        \App\Models\FlightMonitor::create([
            'booking_id' => $b->id, 'flight_number' => 'BA123', 'flight_date' => '2026-07-15',
            'estimated_arrival' => Carbon::parse('2026-07-15 14:00'), 'delay_minutes' => 0,
        ]);

        $drive = $watchdog->estimatedDurationMinutes($b->fresh());
        // Be at the airport by 14:20 (landing + 20); leave by that minus drive + 5.
        $expected = Carbon::parse('2026-07-15 14:20')->subMinutes($drive + 5);

        $this->assertEqualsWithDelta($expected->timestamp, $watchdog->setOffDeadline($b->fresh())->timestamp, 2);
    }

    public function test_urgent_escalation_ten_minutes_before_pickup(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(8));

        $this->tick();

        $this->assertNudged($b, 'set_off_urgent');
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $b->id, 'event_type' => 'nudge_set_off_urgent', 'severity' => 'critical',
        ]);
    }

    public function test_midnight_boundary_job_is_not_missed(): void
    {
        // 23:55 — tomorrow's 00:05 pickup is 10 minutes out. The date has
        // flipped but the watchdog window must still see it (BST-safe: all
        // comparisons in the app clock).
        Carbon::setTestNow('2026-07-15 23:55:00');
        $b = $this->job(BookingStatus::Allocated, Carbon::parse('2026-07-16 00:05:00'));

        $this->tick();

        $this->assertNudged($b, 'set_off_urgent');
    }

    /* ── Max sends + idempotency ──────────────────────────────────────────── */

    public function test_each_nudge_fires_at_most_twice_with_five_minute_gap(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(28));

        $this->tick();                                   // 1st send
        $this->tick();                                   // immediate rerun → no repeat
        $this->assertNudged($b, 'set_off', 1);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->tick();                                   // ≥5 min later, still allocated → repeat
        $this->assertNudged($b, 'set_off', 2);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->tick();                                   // never a third
        $this->assertNudged($b, 'set_off', 2);
    }

    public function test_running_the_command_five_times_in_a_minute_sends_once(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25));

        for ($i = 0; $i < 5; $i++) {
            $this->tick();
        }

        $this->assertNudged($b, 'set_off', 1);
    }

    /* ── Rule 3: arrived detection ────────────────────────────────────────── */

    public function test_arrived_detected_after_dwelling_at_pickup_for_two_minutes(): void
    {
        $b = $this->job(BookingStatus::EnRoute, now()->addMinutes(20), ['pickup' => self::PICKUP]);
        // Three consecutive pings ~50 m from the pickup spanning 2.5 minutes.
        $this->ping($b, 53.4004, -1.5000, now()->subSeconds(150));
        $this->ping($b, 53.4004, -1.5001, now()->subSeconds(90));
        $this->ping($b, 53.4004, -1.5002, now()->subSeconds(30));

        $this->tick();

        $this->assertNudged($b, 'arrived_detect');
    }

    public function test_single_stray_ping_never_triggers_a_geofence_rule(): void
    {
        $b = $this->job(BookingStatus::EnRoute, now()->addMinutes(20), ['pickup' => self::PICKUP]);
        // Driver is still miles away — one jittery fix lands at the pickup.
        $this->ping($b, 53.4500, -1.5000, now()->subSeconds(150)); // ~5.5 km out
        $this->ping($b, 53.4490, -1.5000, now()->subSeconds(90));
        $this->ping($b, 53.4004, -1.5000, now()->subSeconds(30));  // the stray

        $this->tick();

        $this->assertNudged($b, 'arrived_detect', 0);
    }

    public function test_two_close_pings_less_than_two_minutes_apart_do_not_count(): void
    {
        $b = $this->job(BookingStatus::EnRoute, now()->addMinutes(20), ['pickup' => self::PICKUP]);
        $this->ping($b, 53.4004, -1.5000, now()->subSeconds(70));
        $this->ping($b, 53.4004, -1.5001, now()->subSeconds(20));

        $this->tick();

        $this->assertNudged($b, 'arrived_detect', 0);
    }

    /* ── Rule 4: POB detection ────────────────────────────────────────────── */

    public function test_pob_detected_when_driving_away_from_pickup(): void
    {
        $b = $this->job(BookingStatus::Arrived, now()->subMinutes(5), ['pickup' => self::PICKUP]);
        // Pulling away: distances from pickup strictly increasing, both
        // intervals faster than 15 mph (speeds derived from displacement).
        $this->ping($b, 53.4000, -1.5000, now()->subSeconds(150));
        $this->ping($b, 53.4060, -1.5000, now()->subSeconds(90));  // ~667 m in 60 s ≈ 25 mph
        $this->ping($b, 53.4120, -1.5000, now()->subSeconds(30));

        $this->tick();

        $this->assertNudged($b, 'pob_detect');
    }

    public function test_slow_creep_away_from_pickup_is_not_pob(): void
    {
        $b = $this->job(BookingStatus::Arrived, now()->subMinutes(5), ['pickup' => self::PICKUP]);
        // Repositioning the car a few metres — moving away but nowhere near 15 mph.
        $this->ping($b, 53.4000, -1.5000, now()->subSeconds(150));
        $this->ping($b, 53.4001, -1.5000, now()->subSeconds(90));
        $this->ping($b, 53.4002, -1.5000, now()->subSeconds(30));

        $this->tick();

        $this->assertNudged($b, 'pob_detect', 0);
    }

    /* ── Rule 5 + 6: complete ─────────────────────────────────────────────── */

    public function test_complete_detected_when_stationary_at_dropoff(): void
    {
        $b = $this->job(BookingStatus::Collected, now()->subHour(),
            ['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF]);
        // Parked at the drop-off for 3.5 minutes (barely moving).
        $this->ping($b, 53.3589, -2.2727, now()->subSeconds(210));
        $this->ping($b, 53.3590, -2.2727, now()->subSeconds(120));
        $this->ping($b, 53.3590, -2.2728, now()->subSeconds(15));

        $this->tick();

        $this->assertNudged($b, 'complete_detect');
    }

    public function test_complete_fallback_fires_on_the_clock_with_dead_gps(): void
    {
        // POB since a 09:00 pickup, no GPS at all, no stored duration → the
        // 60-min default + 45-min grace has long passed by 12:00.
        $b = $this->job(BookingStatus::Collected, now()->subHours(3));

        $this->tick();

        $this->assertNudged($b, 'complete_fallback');
    }

    public function test_complete_fallback_respects_estimated_duration(): void
    {
        // Same shape but only 30 min since pickup → nothing yet.
        $b = $this->job(BookingStatus::Collected, now()->subMinutes(30));

        $this->tick();

        $this->assertNudged($b, 'complete_fallback', 0);
    }

    /* ── Stale GPS + missing coords ───────────────────────────────────────── */

    public function test_stale_gps_skips_geofence_rules_silently(): void
    {
        $b = $this->job(BookingStatus::EnRoute, now()->addMinutes(20), ['pickup' => self::PICKUP]);
        // Perfect dwell pattern at the pickup — but the newest ping is 10
        // minutes old. Geofence must NOT fire from a ghost position.
        $this->ping($b, 53.4004, -1.5000, now()->subMinutes(13));
        $this->ping($b, 53.4004, -1.5001, now()->subMinutes(10));

        $this->tick();

        $this->assertNudged($b, 'arrived_detect', 0);
        // A web app stops sending GPS the moment the driver backgrounds it, so
        // "GPS stale" would fire on nearly every job — we deliberately stay quiet.
        $this->assertDatabaseMissing('watchdog_events', ['booking_id' => $b->id, 'event_type' => 'gps_stale']);
    }

    public function test_job_without_coords_skips_geofence_but_time_rules_still_apply(): void
    {
        // No meta.geo and no Maps key in tests → geocoding unavailable. The
        // watchdog must not crash, must not geofence, and the clock-based
        // fallback must still nudge.
        $b = $this->job(BookingStatus::Collected, now()->subHours(3));
        $this->ping($b, 53.3589, -2.2727, now()->subSeconds(90));
        $this->ping($b, 53.3590, -2.2727, now()->subSeconds(30));

        $this->tick();

        $this->assertNudged($b, 'complete_detect', 0);
        $this->assertNudged($b, 'complete_fallback', 1);
    }

    /* ── Tracking rule change: GPS starts on Set off, stops on Complete ──── */

    public function test_gps_pings_rejected_before_set_off_and_after_completion(): void
    {
        $service = app(DriverLocationService::class);
        $b = Booking::factory()->create([
            'driver_id' => $this->driver()->id, 'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addMinutes(30),
        ]);
        $driver = $b->driver;

        // Accepted (not set off yet) → rejected.
        $this->assertNull($service->recordPing($driver, 53.4, -1.5));

        // En route / arrived / POB → recorded (arrived used to be wrongly rejected).
        foreach ([BookingStatus::EnRoute, BookingStatus::Arrived, BookingStatus::Collected] as $s) {
            $b->forceFill(['status' => $s->value])->save();
            $this->assertNotNull($service->recordPing($driver, 53.4, -1.5), "ping should record during {$s->value}");
        }

        // Complete → rejected again.
        $b->forceFill(['status' => BookingStatus::Complete->value])->save();
        $this->assertNull($service->recordPing($driver, 53.4, -1.5));
    }

    /* ── Feed rows from existing flows ────────────────────────────────────── */

    public function test_status_changes_write_watchdog_events(): void
    {
        $b = Booking::factory()->create([
            'driver_id' => $this->driver()->id, 'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addMinutes(30),
        ]);

        app(\App\Services\BookingStatusService::class)
            ->transition($b, BookingStatus::EnRoute, $b->driver);

        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $b->id, 'event_type' => 'status_changed']);
    }

    public function test_prune_command_clears_old_watchdog_rows(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25));
        \App\Models\WatchdogEvent::log('status_changed', 'old row', booking: $b);
        \App\Models\WatchdogEvent::query()->update(['occurred_at' => now()->subDays(31)]);
        JobNudge::create([
            'booking_id' => $b->id, 'nudge_type' => 'set_off', 'recipient_type' => 'driver',
            'sent_at' => now()->subDays(31), 'channel' => 'push', 'created_at' => now()->subDays(31),
        ]);

        $this->artisan('cet:prune-gps')->assertSuccessful();

        $this->assertDatabaseCount('watchdog_events', 0);
        $this->assertDatabaseCount('job_nudges', 0);
    }
}
