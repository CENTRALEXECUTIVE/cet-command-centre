<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\Telephony\MaskingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shared / multi-pickup jobs: the masked line FOLLOWS the journey. The party
 * being collected is live; once they're aboard their number drops and the next
 * pickup's goes live. Real numbers are admin-only — the driver only ever gets
 * the masked line for whoever is currently live.
 */
class SharedPickupMaskingTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 08:00:00');
        config([
            'services.twilio_masking.customer_line' => '+441111111111',
            'services.twilio_masking.driver_line' => '+442222222222',
        ]);
        // A cover (non-admin) driver, so masking is on and no real number shows.
        $this->driver = User::factory()->driver()->create(['phone' => '07111222333']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sharedJob(): Booking
    {
        $lead = Customer::create(['name' => 'Margaret Moran', 'phone' => '07544616024']);

        return Booking::factory()->create([
            'customer_id' => $lead->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addMinutes(20), // inside the masking window
            'pickup_address' => '3 Avill Way, Rotherham, S66 1DL',
            'destination_address' => 'Cruise Terminal One, Dover, CT17 9DQ',
            'meta' => [
                'stops' => [['address' => '47 Lockwood Avenue, South Anston, S25 5GQ']],
                // ADMIN-ONLY second-party contact — never shown to the driver.
                'stop_contacts' => [['name' => 'Barbara Horsfield', 'phone' => '07986942673']],
            ],
        ]);
    }

    public function test_the_current_party_advances_as_each_pickup_is_collected(): void
    {
        $b = $this->sharedJob();

        $this->assertTrue($b->hasMultiplePickupParties());
        $this->assertCount(2, $b->pickupParties());

        // Leg 1 — heading to the lead pickup.
        $this->assertSame(0, $b->activePickupIndex());
        $this->assertStringEndsWith('7544616024', $b->currentCustomerContactNumber());
        $this->assertSame('Margaret Moran', $b->currentPickupName());

        // Lead aboard → the next pickup is now live.
        $b->update(['status' => BookingStatus::Collected]);
        $b->refresh();
        $this->assertSame(1, $b->activePickupIndex());
        $this->assertStringEndsWith('7986942673', $b->currentCustomerContactNumber());
        $this->assertSame('Barbara Horsfield', $b->currentPickupName());
        $this->assertFalse($b->pickupsAllCollected());

        // Second party aboard → everyone collected.
        $b->markStopPickedUp(0);
        $b->refresh();
        $this->assertTrue($b->pickupsAllCollected());
    }

    public function test_a_single_customer_job_is_unchanged(): void
    {
        $solo = Customer::create(['name' => 'Solo', 'phone' => '07000000000']);
        $b = Booking::factory()->create([
            'customer_id' => $solo->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addMinutes(20),
        ]);

        $this->assertFalse($b->hasMultiplePickupParties());
        $this->assertSame($b->customerContactNumber(), $b->currentCustomerContactNumber());
    }

    public function test_an_admin_can_save_pickup_contacts_and_they_stay_office_only(): void
    {
        $lead = Customer::create(['name' => 'Margaret Moran', 'phone' => '07544616024']);
        $b = Booking::factory()->create([
            'customer_id' => $lead->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addMinutes(20),
            'meta' => ['stops' => [['address' => '47 Lockwood Avenue, S25 5GQ']]],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.pickup-contacts', $b), [
                'contacts' => [0 => ['name' => 'Barbara Horsfield', 'phone' => '07986942673']],
            ])
            ->assertRedirect();

        $b->refresh();
        $this->assertSame('07986942673', $b->meta['stop_contacts'][0]['phone']);
        $this->assertTrue($b->hasMultiplePickupParties());

        // The number is NEVER rendered on the driver's job screen.
        $page = $this->actingAs($this->driver)->get(route('driver.job', $b))->assertOk();
        $page->assertDontSee('07986942673');
    }

    public function test_the_admin_booking_page_shows_the_pickup_panel_and_the_live_marker(): void
    {
        $b = $this->sharedJob();
        $admin = User::factory()->admin()->create();

        $page = $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk();
        $page->assertSee('Per-pickup contacts');
        $page->assertSee('47 Lockwood Avenue', false);
        $page->assertSee('7986942673', false); // admin CAN see the number (office-side)
        $page->assertSee('LIVE NOW'); // the lead is live before any collection
    }

    public function test_the_driver_screen_names_the_next_pickup_but_never_shows_its_number(): void
    {
        $lead = Customer::create(['name' => 'Margaret Moran', 'phone' => '07544616024']);
        $b = Booking::factory()->create([
            'customer_id' => $lead->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Collected, // lead aboard, heading to the stop
            'pickup_at' => now()->subMinutes(10),
            'meta' => [
                'stops' => [['address' => '47 Lockwood Avenue, S25 5GQ']],
                'stop_contacts' => [['name' => 'Barbara Horsfield', 'phone' => '07986942673']],
            ],
        ]);

        $page = $this->actingAs($this->driver)->get(route('driver.job', $b))->assertOk();
        $page->assertSee('Barbara Horsfield');       // the driver DOES see who's next
        $page->assertSee('Now collecting', false);
        $page->assertDontSee('07986942673');         // but NEVER the number
    }

    public function test_a_driver_cannot_save_pickup_contacts(): void
    {
        $b = $this->sharedJob();

        $this->actingAs($this->driver)
            ->post(route('bookings.pickup-contacts', $b), [
                'contacts' => [0 => ['name' => 'Hacker', 'phone' => '07000000000']],
            ])
            ->assertForbidden();
    }

    public function test_a_blank_number_clears_a_stop_contact(): void
    {
        $b = $this->sharedJob();
        $this->assertTrue($b->hasMultiplePickupParties());
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.pickup-contacts', $b), ['contacts' => [0 => ['name' => '', 'phone' => '']]])
            ->assertRedirect();

        $this->assertFalse($b->fresh()->hasMultiplePickupParties());
    }

    public function test_the_proxy_session_follows_the_journey_through_the_real_driver_flow(): void
    {
        // Twilio Proxy configured + faked — prove the mask actually re-points at
        // the next party when the driver collects a pickup, via the REAL flow.
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'secret',
            'services.twilio.proxy_service_sid' => 'KS_test',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'proxy.twilio.com/v1/Services/KS_test/Sessions' => \Illuminate\Support\Facades\Http::response(['sid' => 'KC1', 'status' => 'open']),
            'proxy.twilio.com/v1/Services/KS_test/Sessions/KC1/Participants' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['sid' => 'C1', 'proxy_identifier' => '+447700000001'])
                ->push(['sid' => 'D1', 'proxy_identifier' => '+447700000009'])
                ->push(['sid' => 'C2', 'proxy_identifier' => '+447700000002'])
                ->push(['sid' => 'D2', 'proxy_identifier' => '+447700000009']),
            'proxy.twilio.com/v1/Services/KS_test/Sessions/KC1' => \Illuminate\Support\Facades\Http::response(['sid' => 'KC1', 'status' => 'closed']),
            'api.twilio.com/*' => \Illuminate\Support\Facades\Http::response(['sid' => 'SM']),
        ]);

        $b = $this->sharedJob();
        $b->update(['status' => BookingStatus::Arrived]); // en route done, at the lead pickup
        $proxy = app(\App\Services\Telephony\TwilioProxyService::class);

        // Mask opens on the LEAD party.
        $proxy->openSession($b->fresh(['customer', 'driver']), $this->driver);
        $this->assertStringEndsWith('7544616024', (string) $b->fresh()->meta['proxy_active_phone']);

        // Driver marks passenger on board → the POB handler swaps the mask to the
        // next pickup (the second party) because more pickups remain.
        app(\App\Services\BookingStatusService::class)->transition($b->fresh(['customer', 'driver']), BookingStatus::Collected, $this->driver);
        $this->assertStringEndsWith('7986942673', (string) $b->fresh()->meta['proxy_active_phone']);

        // A session was closed and a fresh one opened (the swap actually happened).
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Sessions/KC1'));

        // Driver picks up the second party at the stop → all aboard, mask stays put.
        \App\Http\Controllers\Driver\JobController::handleStopEvent($b->fresh(['customer', 'driver']), 'picked_up');
        $this->assertTrue($b->fresh()->pickupsAllCollected());
    }

    public function test_three_pickups_advance_in_order(): void
    {
        $lead = Customer::create(['name' => 'One', 'phone' => '07000000001']);
        $b = Booking::factory()->create([
            'customer_id' => $lead->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addMinutes(20),
            'meta' => [
                'stops' => [['address' => 'Stop A'], ['address' => 'Stop B']],
                'stop_contacts' => [
                    ['name' => 'Two', 'phone' => '07000000002'],
                    ['name' => 'Three', 'phone' => '07000000003'],
                ],
            ],
        ]);

        $this->assertCount(3, $b->pickupParties());
        $this->assertStringEndsWith('0000001', $b->currentCustomerContactNumber()); // party 1 live

        $b->update(['status' => BookingStatus::Collected]);           // party 1 aboard
        $this->assertStringEndsWith('0000002', $b->fresh()->currentCustomerContactNumber());

        $b->refresh()->markStopPickedUp(0);                           // party 2 aboard
        $this->assertStringEndsWith('0000003', $b->fresh()->currentCustomerContactNumber());

        $b->refresh()->markStopPickedUp(1);                           // party 3 aboard
        $this->assertTrue($b->fresh()->pickupsAllCollected());
    }

    public function test_the_switchboard_bridges_the_driver_to_the_live_party_only(): void
    {
        $b = $this->sharedJob();
        $masking = app(MaskingService::class);

        // Driver rings the line → reaches the LEAD (leg 1).
        $asDriver = $masking->resolve('07111222333');
        $this->assertSame('customer', $asDriver['to']);
        $this->assertStringEndsWith('7544616024', $asDriver['dial']);

        // The lead can reach the driver; the not-yet-live second party cannot.
        $this->assertSame('driver', $masking->resolve('07544616024')['to']);
        $this->assertTrue($masking->resolve('07986942673')['office'] ?? false);
        $this->assertSame('not_live', $masking->resolve('07986942673')['reason']);

        // Lead aboard → the line now follows to the second party.
        $b->update(['status' => BookingStatus::Collected]);

        $this->assertStringEndsWith('7986942673', $masking->resolve('07111222333')['dial']);
        $this->assertSame('driver', $masking->resolve('07986942673')['to']);
        // The collected lead is no longer bridged mid-journey.
        $this->assertSame('not_live', $masking->resolve('07544616024')['reason']);
    }
}
