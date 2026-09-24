<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\WatchdogEvent;
use App\Services\Push\WebPushService;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * NEVER blare next to a passenger: an admin who is themselves on a live job (or
 * has held their alerts) gets no push — but the alert still lands on the feed and
 * any other director still receives it.
 */
class AdminAlertSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_on_a_live_job_is_not_pushed_but_a_free_admin_is(): void
    {
        $free = User::factory()->admin()->create(['name' => 'Free Director']);
        $busy = User::factory()->admin()->create(['name' => 'Driving Director']);

        // The busy director is mid-job with a passenger.
        Booking::factory()->create(['driver_id' => $busy->id, 'status' => BookingStatus::Collected->value]);
        $this->assertTrue($busy->busyForAlerts());
        $this->assertFalse($free->busyForAlerts());

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')
            ->once()
            ->with(Mockery::on(fn (User $u) => $u->id === $free->id), Mockery::any(), Mockery::any(), Mockery::any());
        $push->shouldReceive('sendToUser')
            ->never()
            ->with(Mockery::on(fn (User $u) => $u->id === $busy->id), Mockery::any(), Mockery::any(), Mockery::any());
        $this->app->instance(WebPushService::class, $push);

        $subject = Booking::factory()->create(['status' => BookingStatus::Pending->value]);
        app(AdminAlerts::class)->send($subject, 'admin_unallocated', 'unallocated',
            '🔴 Unallocated', 'A job needs a driver', severity: 'critical', maxSends: null, repeatMinutes: 30);

        // The alert is still recorded on the feed for the busy director to see later.
        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'admin_unallocated']);
    }

    public function test_holding_alerts_also_suppresses_the_push(): void
    {
        $held = User::factory()->admin()->create();
        $held->holdAlertsFor(120);

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->never();
        $this->app->instance(WebPushService::class, $push);

        app(AdminAlerts::class)->notify('unallocated', 'x', 'y', 'critical');

        $this->assertTrue(true); // no push expectations violated
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
