<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\ProxySession;
use App\Models\User;
use App\Services\BookingStatusService;
use App\Services\Telephony\TwilioProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Number masking (Twilio Proxy sessions): drivers never see the customer's
 * real number, customers never see the driver's; the mask opens on dispatch,
 * dies on complete/cancel/reassign and via the scheduled expiry sweep.
 */
class ProxyMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_CUSTOMER = '07700900111';

    private const REAL_DRIVER = '07700900222';

    private const MASK_FOR_DRIVER = '+447700333333';

    private const MASK_FOR_CUSTOMER = '+447700444444';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'secret',
            'services.twilio.proxy_service_sid' => 'KS_test',
        ]);
        $this->fakeProxyApi();
    }

    private function fakeProxyApi(): void
    {
        Http::fake([
            'proxy.twilio.com/v1/Services/KS_test/Sessions' => Http::response(['sid' => 'KC_session_1', 'status' => 'open']),
            'proxy.twilio.com/v1/Services/KS_test/Sessions/KC_session_1/Participants' => Http::sequence()
                ->push(['sid' => 'KP_customer', 'proxy_identifier' => self::MASK_FOR_CUSTOMER])
                ->push(['sid' => 'KP_driver', 'proxy_identifier' => self::MASK_FOR_DRIVER])
                ->push(['sid' => 'KP_customer2', 'proxy_identifier' => self::MASK_FOR_CUSTOMER])
                ->push(['sid' => 'KP_driver2', 'proxy_identifier' => self::MASK_FOR_DRIVER]),
            'proxy.twilio.com/v1/Services/KS_test/Sessions/KC_session_1' => Http::response(['sid' => 'KC_session_1', 'status' => 'closed']),
            'api.twilio.com/*' => Http::response(['sid' => 'SM_x']), // WhatsApp sends
        ]);
    }

    private function driver(string $phone = self::REAL_DRIVER): User
    {
        $driver = User::factory()->driver()->create(['phone' => $phone]);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        return $driver;
    }

    private function booking(BookingStatus $status = BookingStatus::Pending, ?User $driver = null): Booking
    {
        $booking = Booking::factory()->create([
            'status' => $status,
            'driver_id' => $driver?->id,
            'pickup_at' => now()->addHours(3),
        ]);
        $booking->customer->forceFill(['phone' => self::REAL_CUSTOMER])->save();

        return $booking->fresh(['customer']);
    }

    public function test_allocating_a_driver_opens_a_proxy_session_automatically(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking();

        app(BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $session = ProxySession::where('booking_id', $booking->id)->open()->first();
        $this->assertNotNull($session, 'Dispatch should open a masking session');
        $this->assertSame(self::MASK_FOR_DRIVER, $session->masked_number);
        $this->assertSame(self::MASK_FOR_CUSTOMER, $session->customer_masked_number);
        $this->assertSame($driver->id, $session->driver_id);
        $this->assertNotNull($session->closes_at);
        // Audit trail has the open.
        $this->assertDatabaseHas('proxy_events', ['booking_id' => $booking->id, 'event_type' => 'session_opened']);
    }

    public function test_driver_job_screen_shows_only_the_masked_number(): void
    {
        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Accepted, $driver);
        app(TwilioProxyService::class)->openSession($booking, $driver);

        $page = $this->actingAs($driver)->get(route('driver.job', $booking))->assertOk();

        $page->assertSee(self::MASK_FOR_DRIVER);
        $page->assertDontSee(self::REAL_CUSTOMER);
        $page->assertDontSee('+44'.substr(self::REAL_CUSTOMER, 1)); // normalised form too
    }

    public function test_driver_sees_no_number_at_all_when_masking_is_not_active(): void
    {
        config(['services.twilio.proxy_service_sid' => null]); // masking off

        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Accepted, $driver);

        $page = $this->actingAs($driver)->get(route('driver.job', $booking))->assertOk();

        // Leak-proof default: no real number, pointed at the office instead.
        $page->assertDontSee(self::REAL_CUSTOMER);
        $page->assertSee('Via the office');
    }

    public function test_customer_driver_details_use_the_masked_line(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking();

        app(BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $details = $booking->messages()->where('type', 'driver_details')->latest('id')->first();
        $this->assertNotNull($details);
        $this->assertStringContainsString(self::MASK_FOR_CUSTOMER, $details->body);
        $this->assertStringNotContainsString(self::REAL_DRIVER, $details->body);
    }

    public function test_completing_the_job_closes_the_session(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Collected, $driver);
        app(TwilioProxyService::class)->openSession($booking, $driver);

        app(BookingStatusService::class)->forceTransition($booking, BookingStatus::Complete, $admin);

        $session = ProxySession::where('booking_id', $booking->id)->first();
        $this->assertSame('closed', $session->status);
        $this->assertNotNull($session->closed_at);
        $this->assertDatabaseHas('proxy_events', ['booking_id' => $booking->id, 'event_type' => 'session_closed']);
    }

    public function test_reaching_pob_closes_the_session_so_the_number_frees_up(): void
    {
        // Once the passenger is on board there's no more phone contact — the
        // mask closes at POB (not drop-off + grace) so the pooled number is
        // free for the next job and a later call can't reach a reassigned pair.
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Arrived, $driver);
        app(TwilioProxyService::class)->openSession($booking, $driver);

        app(BookingStatusService::class)->forceTransition($booking, BookingStatus::Collected, $admin);

        $session = ProxySession::where('booking_id', $booking->id)->first();
        $this->assertSame('closed', $session->status, 'The mask must close at POB');
        $this->assertNotNull($session->closed_at);
    }

    public function test_reassigning_the_driver_swaps_the_session(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->driver();
        $second = $this->driver('07700900555');
        $booking = $this->booking(BookingStatus::EnRoute, $first);
        app(TwilioProxyService::class)->openSession($booking, $first);

        $this->actingAs($admin)->post(route('despatch.reassign', $booking), ['driver_id' => $second->id])
            ->assertRedirect();

        $sessions = ProxySession::where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertSame('closed', $sessions->first()->status, 'Old driver must lose the line immediately');
        $open = $sessions->where('status', 'open');
        $this->assertCount(1, $open);
        $this->assertSame($second->id, $open->first()->driver_id);
    }

    public function test_scheduled_sweep_closes_sessions_past_their_expiry(): void
    {
        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Collected, $driver);
        $session = app(TwilioProxyService::class)->openSession($booking, $driver);
        $session->forceFill(['closes_at' => now()->subMinute()])->save();

        $this->artisan('cet:close-proxy-sessions')->assertSuccessful();

        $this->assertSame('closed', $session->fresh()->status);
    }

    public function test_masking_is_a_silent_noop_when_unconfigured(): void
    {
        config(['services.twilio.proxy_service_sid' => null]);
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking();

        app(BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $this->assertDatabaseCount('proxy_sessions', 0);
        $this->assertSame('allocated', $booking->fresh()->status->value); // dispatch unaffected
    }

    public function test_twilio_failure_never_blocks_dispatch(): void
    {
        // A different service sid dodges the happy-path stubs from setUp, so
        // the wildcard 500 below is what the service actually hits.
        config(['services.twilio.proxy_service_sid' => 'KS_broken']);
        Http::fake(['proxy.twilio.com/*' => Http::response('boom', 500), 'api.twilio.com/*' => Http::response(['sid' => 'SM_x'])]);
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking();

        app(BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $this->assertSame('allocated', $booking->fresh()->status->value);
        $this->assertDatabaseHas('proxy_events', ['booking_id' => $booking->id, 'event_type' => 'open_failed']);
    }

    public function test_webhook_logs_proxy_events_with_bodies_stripped(): void
    {
        config(['cet.webhook_secret' => 'shh']);
        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Accepted, $driver);
        $session = app(TwilioProxyService::class)->openSession($booking, $driver);

        $this->post(route('webhooks.twilio-proxy'), [
            'secret' => 'shh',
            'interactionSessionSid' => $session->twilio_session_sid,
            'interactionType' => 'Message',
            'Body' => 'my private message',
        ])->assertOk();

        $event = \App\Models\ProxyEvent::where('event_type', 'Message')->first();
        $this->assertNotNull($event);
        $this->assertSame($booking->id, $event->booking_id);
        $this->assertArrayNotHasKey('Body', $event->payload, 'Message bodies must not be stored');
    }

    public function test_expired_masking_data_is_purged_on_the_gps_schedule(): void
    {
        $driver = $this->driver();
        $booking = $this->booking(BookingStatus::Accepted, $driver);
        $session = app(TwilioProxyService::class)->openSession($booking, $driver);
        $session->forceFill(['status' => 'closed', 'created_at' => now()->subDays(91)])->save();
        \App\Models\ProxyEvent::query()->update(['occurred_at' => now()->subDays(91)]);

        $this->artisan('cet:prune-gps')->assertSuccessful();

        $this->assertDatabaseCount('proxy_sessions', 0);
        $this->assertDatabaseCount('proxy_events', 0);
    }
}
