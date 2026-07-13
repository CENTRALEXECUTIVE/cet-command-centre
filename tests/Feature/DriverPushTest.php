<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Driver push notifications: a device can subscribe, allocating a job pings the
 * driver, and nothing breaks when VAPID keys aren't configured yet.
 */
class DriverPushTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => false]);

        return $driver;
    }

    private function configureVapid(): void
    {
        // Deterministic test keys (valid VAPID pair) so the service is "configured".
        config([
            'webpush.vapid.subject' => 'mailto:test@cet.co.uk',
            'webpush.vapid.public_key' => 'BAphgQBPd1Wuyz-6V58Qvz-TH5al3Sd3XDbNFHyKxZCyPqsSe1EAUOhvQDwp6NIwy4Y0W_KRS63SduwByM6AsNE',
            'webpush.vapid.private_key' => 'eTCYvfSyOjK8aEfi5h8xlbz3SeYyAinisZD1e5eMO30',
        ]);
    }

    public function test_a_driver_can_subscribe_a_device(): void
    {
        $this->configureVapid();
        $driver = $this->driver();

        $this->actingAs($driver)->getJson(route('driver.push.key'))
            ->assertOk()
            ->assertJson(['enabled' => true])
            ->assertJsonPath('key', config('webpush.vapid.public_key'));

        $this->actingAs($driver)->postJson(route('driver.push.subscribe'), [
            'endpoint' => 'https://push.example.com/abc123',
            'keys' => ['p256dh' => 'BPUBLICKEYXYZ', 'auth' => 'AUTHSECRET'],
        ])->assertOk()->assertJson(['subscribed' => true]);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $driver->id,
            'endpoint' => 'https://push.example.com/abc123',
        ]);

        // Re-subscribing the same endpoint updates, never duplicates.
        $this->actingAs($driver)->postJson(route('driver.push.subscribe'), [
            'endpoint' => 'https://push.example.com/abc123',
            'keys' => ['p256dh' => 'BNEWKEY', 'auth' => 'NEWAUTH'],
        ])->assertOk();
        $this->assertSame(1, PushSubscription::where('user_id', $driver->id)->count());
    }

    public function test_a_driver_can_unsubscribe(): void
    {
        $driver = $this->driver();
        PushSubscription::create([
            'user_id' => $driver->id,
            'endpoint' => 'https://push.example.com/gone',
            'endpoint_hash' => hash('sha256', 'https://push.example.com/gone'),
            'public_key' => 'k', 'auth_token' => 'a',
        ]);

        $this->actingAs($driver)->postJson(route('driver.push.unsubscribe'), [
            'endpoint' => 'https://push.example.com/gone',
        ])->assertOk()->assertJson(['subscribed' => false]);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_allocating_a_job_pushes_to_the_driver(): void
    {
        $this->configureVapid();
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        PushSubscription::create([
            'user_id' => $driver->id,
            'endpoint' => 'https://push.example.com/dev1',
            'endpoint_hash' => hash('sha256', 'https://push.example.com/dev1'),
            'public_key' => 'BPUB', 'auth_token' => 'AUTH',
        ]);

        // Capture the push instead of sending it for real.
        $spy = \Mockery::mock(WebPushService::class);
        $spy->shouldReceive('configured')->andReturnTrue();
        $spy->shouldReceive('sendToUser')->once()
            ->with(
                \Mockery::on(fn ($u) => $u->id === $driver->id),
                \Mockery::on(fn ($title) => str_contains($title, 'New job')),
                \Mockery::any(),
                \Mockery::on(fn ($opts) => str_contains($opts['url'] ?? '', '/driver/jobs/'))
            )
            ->andReturn(1);
        $this->instance(WebPushService::class, $spy);

        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);
        app(\App\Services\BookingStatusService::class)->allocateDriver($booking, $driver, $admin);
    }

    public function test_admin_can_send_themselves_a_test_notification(): void
    {
        $this->configureVapid();
        $admin = User::factory()->admin()->create();

        // Don't hit the network — confirm the endpoint pushes to the caller.
        $spy = \Mockery::mock(WebPushService::class);
        $spy->shouldReceive('configured')->andReturnTrue();
        $spy->shouldReceive('sendToUser')->once()
            ->with(\Mockery::on(fn ($u) => $u->id === $admin->id), \Mockery::any(), \Mockery::any(), \Mockery::any())
            ->andReturn(2);
        $this->instance(WebPushService::class, $spy);

        $this->actingAs($admin)->postJson(route('driver.push.test'))
            ->assertOk()
            ->assertJson(['ok' => true, 'devices' => 2]);
    }

    public function test_test_notification_reports_when_push_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson(route('driver.push.test'))
            ->assertStatus(422)
            ->assertJson(['reason' => 'not_configured']);
    }

    public function test_make_vapid_show_prints_the_env_lines_without_writing(): void
    {
        // --show must never touch .env — it just prints the two lines.
        $this->artisan('cet:make-vapid --show')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->assertSuccessful();
    }

    public function test_push_is_a_no_op_when_vapid_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        $driver = $this->driver();
        PushSubscription::create([
            'user_id' => $driver->id,
            'endpoint' => 'https://push.example.com/x',
            'endpoint_hash' => hash('sha256', 'https://push.example.com/x'),
            'public_key' => 'k', 'auth_token' => 'a',
        ]);

        $sent = app(WebPushService::class)->sendToUser($driver, 'Hi', 'There');
        $this->assertSame(0, $sent);
    }
}
