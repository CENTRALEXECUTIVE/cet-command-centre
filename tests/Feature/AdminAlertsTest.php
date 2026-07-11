<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\JobNudge;
use App\Models\User;
use App\Models\WatchdogEvent;
use App\Services\Push\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Records every push the app would send, so preference filtering is
 * observable without VAPID keys.
 */
class FakePush extends WebPushService
{
    /** @var array<int, array{user: int, title: string}> */
    public array $sent = [];

    public function sendToUser(User $user, string $title, string $body, array $options = []): int
    {
        $this->sent[] = ['user' => $user->id, 'title' => $title];

        return 1;
    }

    public function to(User $user): array
    {
        return array_values(array_filter($this->sent, fn ($s) => $s['user'] === $user->id));
    }
}

/**
 * Phase 2 gate: escalation timing, preference filtering, unallocated repeat
 * cadence, the acknowledge flow, and the feed endpoint's ordering/severity.
 */
class AdminAlertsTest extends TestCase
{
    use RefreshDatabase;

    private FakePush $push;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
        $this->push = new FakePush;
        $this->app->instance(WebPushService::class, $this->push);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(array $attrs = []): User
    {
        return User::factory()->admin()->create($attrs);
    }

    private function driverJob(BookingStatus $status, Carbon $pickup): Booking
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        return Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => $status, 'pickup_at' => $pickup,
        ]);
    }

    private function tick(): void
    {
        $this->artisan('cet:status-watchdog')->assertSuccessful();
    }

    /* ── Escalation: driver ignoring nudges ──────────────────────────────── */

    public function test_unacted_nudge_escalates_to_admins_after_five_minutes(): void
    {
        $admin = $this->admin();
        $b = $this->driverJob(BookingStatus::Allocated, now()->addMinutes(28));

        // Both driver sends happen, 6 minutes apart.
        $this->tick();
        Carbon::setTestNow(now()->addMinutes(6));
        $this->tick();
        $this->assertSame(2, JobNudge::where('booking_id', $b->id)->where('recipient_type', 'driver')->count());

        // 4 minutes after the 2nd send — not yet.
        Carbon::setTestNow(now()->addMinutes(4));
        $this->tick();
        $this->assertDatabaseMissing('job_nudges', ['booking_id' => $b->id, 'nudge_type' => 'admin_unacted_set_off']);

        // 6 minutes after the 2nd send, still not set off → escalate, once.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->tick();
        $this->tick();
        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_unacted_set_off')->count());
        $this->assertNotEmpty($this->push->to($admin));
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $b->id, 'event_type' => 'admin_unacted_set_off', 'severity' => 'critical',
        ]);
    }

    public function test_no_escalation_when_the_driver_acted(): void
    {
        $this->admin();
        $b = $this->driverJob(BookingStatus::Allocated, now()->addMinutes(28));

        $this->tick();
        Carbon::setTestNow(now()->addMinutes(6));
        $this->tick();

        // Driver sets off before the unacted window closes.
        $b->forceFill(['status' => BookingStatus::EnRoute->value])->save();

        Carbon::setTestNow(now()->addMinutes(10));
        $this->tick();

        $this->assertDatabaseMissing('job_nudges', ['booking_id' => $b->id, 'nudge_type' => 'admin_unacted_set_off']);
    }

    /* ── Escalation: unallocated near pickup ─────────────────────────────── */

    public function test_unallocated_job_alerts_every_thirty_minutes_until_allocated(): void
    {
        $admin = $this->admin();
        $b = Booking::factory()->create(['status' => BookingStatus::Pending, 'pickup_at' => now()->addMinutes(90)]);

        $this->tick();
        $this->tick(); // immediate rerun → no repeat
        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_unallocated')->count());
        $this->assertCount(1, $this->push->to($admin));

        Carbon::setTestNow(now()->addMinutes(31));
        $this->tick();
        $this->assertSame(2, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_unallocated')->count());

        // Allocated → the repeats stop.
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        $b->forceFill(['status' => BookingStatus::Allocated->value, 'driver_id' => $driver->id])->save();

        Carbon::setTestNow(now()->addMinutes(31));
        $this->tick();
        $this->assertSame(2, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_unallocated')->count());
    }

    public function test_unallocated_far_from_pickup_is_quiet(): void
    {
        $this->admin();
        Booking::factory()->create(['status' => BookingStatus::Pending, 'pickup_at' => now()->addHours(3)]);

        $this->tick();

        $this->assertDatabaseMissing('job_nudges', ['nudge_type' => 'admin_unallocated']);
    }

    /* ── Escalation: GPS lost mid-job ────────────────────────────────────── */

    public function test_gps_lost_mid_job_warns_the_office_once(): void
    {
        $admin = $this->admin();
        $b = $this->driverJob(BookingStatus::EnRoute, now()->addMinutes(30));
        DriverLocation::create([
            'driver_id' => $b->driver_id, 'booking_id' => $b->id,
            'latitude' => 53.4, 'longitude' => -1.5, 'captured_at' => now()->subMinutes(6),
        ]);

        $this->tick();
        Carbon::setTestNow(now()->addMinutes(40));
        $this->tick(); // still lost much later → no second warning

        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_gps_lost')->count());
        $this->assertCount(1, $this->push->to($admin));
    }

    /* ── Preference filtering ────────────────────────────────────────────── */

    public function test_preferences_filter_event_types_per_admin(): void
    {
        $muted = $this->admin(['notification_preferences' => ['unallocated' => false]]);
        $normal = $this->admin();
        Booking::factory()->create(['status' => BookingStatus::Pending, 'pickup_at' => now()->addMinutes(90)]);

        $this->tick();

        $this->assertCount(0, $this->push->to($muted));
        $this->assertCount(1, $this->push->to($normal));
    }

    public function test_critical_only_master_switch_mutes_lower_severities(): void
    {
        $criticalOnly = $this->admin(['notification_preferences' => ['critical_only' => true]]);

        // A warning (GPS lost) is muted…
        $b = $this->driverJob(BookingStatus::EnRoute, now()->addMinutes(30));
        DriverLocation::create([
            'driver_id' => $b->driver_id, 'booking_id' => $b->id,
            'latitude' => 53.4, 'longitude' => -1.5, 'captured_at' => now()->subMinutes(6),
        ]);
        $this->tick();
        $this->assertCount(0, $this->push->to($criticalOnly));

        // …but a critical (unallocated) still gets through.
        Booking::factory()->create(['status' => BookingStatus::Pending, 'pickup_at' => now()->addMinutes(90)]);
        $this->tick();
        $this->assertCount(1, $this->push->to($criticalOnly));
    }

    public function test_no_show_and_cancel_push_to_admins_immediately(): void
    {
        $admin = $this->admin();
        $b = $this->driverJob(BookingStatus::Arrived, now());

        app(\App\Services\BookingStatusService::class)
            ->forceTransition($b, BookingStatus::NoShow, $admin, note: 'test');

        $titles = array_column($this->push->to($admin), 'title');
        $this->assertNotEmpty(array_filter($titles, fn ($t) => str_contains($t, 'No Show')));
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $b->id, 'event_type' => 'no_show']);
    }

    /* ── Feed endpoint + acknowledge flow ────────────────────────────────── */

    public function test_feed_returns_newest_first_with_severity_and_is_admin_only(): void
    {
        $admin = $this->admin();
        WatchdogEvent::log('status_changed', 'older row', 'info');
        Carbon::setTestNow(now()->addMinute());
        WatchdogEvent::log('admin_unallocated', 'newest row', 'critical');

        $response = $this->actingAs($admin)->getJson(route('alerts.feed'))->assertOk();
        $events = $response->json('events');

        $this->assertSame('newest row', $events[0]['title']);
        $this->assertSame('critical', $events[0]['severity']);
        $this->assertSame('older row', $events[1]['title']);
        $this->assertSame(1, $response->json('critical'));

        // Drivers cannot read the office feed.
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->getJson(route('alerts.feed'))->assertForbidden();
    }

    public function test_acknowledging_a_critical_clears_the_badge(): void
    {
        $admin = $this->admin();
        $event = WatchdogEvent::log('admin_unallocated', 'needs eyes', 'critical');

        $this->actingAs($admin)->postJson(route('alerts.ack', $event))
            ->assertOk()
            ->assertJson(['ok' => true, 'critical' => 0]);

        $this->assertNotNull($event->fresh()->acknowledged_at);
    }

    /* ── Preferences page ────────────────────────────────────────────────── */

    public function test_only_a_super_admin_manages_notification_preferences(): void
    {
        $super = $this->admin(['is_super_admin' => true]);
        $plain = $this->admin();

        $this->actingAs($plain)->get(route('notifications.index'))->assertForbidden();
        $this->actingAs($super)->get(route('notifications.index'))->assertOk()->assertSee($plain->name);

        $this->actingAs($super)->put(route('notifications.update'), [
            'prefs' => [$plain->id => ['unallocated' => '1', 'critical_only' => '1']],
        ])->assertRedirect();

        $prefs = $plain->fresh()->alertPreferences();
        $this->assertTrue($prefs['unallocated']);
        $this->assertTrue($prefs['critical_only']);
        $this->assertFalse($prefs['gps_lost']); // unchecked box saved as off
    }
}
