<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Arrived" can only be marked at the pickup (within ~1 mile), but a driver who
 * is genuinely there is NEVER blocked: a far GPS fix is rejected, while no fix /
 * location off is allowed through.
 */
class ArrivalGeofenceTest extends TestCase
{
    use RefreshDatabase;

    /** Sheffield-ish pickup. */
    private const PICKUP = [53.4000, -1.5000];

    private function driver(): User
    {
        return User::factory()->driver()->create();
    }

    private function job(User $driver): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::EnRoute->value,
            'pickup_at' => now()->addMinutes(5),
            // Pre-set the geocoded pickup so the check doesn't hit the network.
            'meta' => ['geo' => ['pickup' => self::PICKUP]],
        ]);
    }

    /* ── Model ───────────────────────────────────────────────────────────── */

    public function test_check_is_ok_when_within_the_radius(): void
    {
        $b = $this->job($this->driver());
        // ~450 m north of the pickup → well within a mile.
        $this->assertSame('ok', $b->checkDriverAtPickup(53.4040, -1.5000, 20));
    }

    public function test_check_is_far_when_miles_away(): void
    {
        $b = $this->job($this->driver());
        // London → hundreds of km away.
        $this->assertSame('far', $b->checkDriverAtPickup(51.5074, -0.1278, 20));
    }

    public function test_check_is_no_location_without_a_fix(): void
    {
        $b = $this->job($this->driver());
        $this->assertSame('no_location', $b->checkDriverAtPickup(null, null, null));
    }

    /* ── Endpoint ────────────────────────────────────────────────────────── */

    public function test_arrived_is_blocked_when_gps_is_far(): void
    {
        $driver = $this->driver();
        $b = $this->job($driver);

        $this->actingAs($driver)
            ->post(route('driver.job.status', $b), [
                'status' => 'arrived', 'lat' => 51.5074, 'lng' => -0.1278, 'accuracy' => 20,
            ])
            ->assertRedirect()
            ->assertSessionHas('arriveError');

        $this->assertSame(BookingStatus::EnRoute, $b->fresh()->status); // not marked arrived
    }

    public function test_arrived_goes_through_when_close(): void
    {
        $driver = $this->driver();
        $b = $this->job($driver);

        $this->actingAs($driver)
            ->post(route('driver.job.status', $b), [
                'status' => 'arrived', 'lat' => 53.4040, 'lng' => -1.5000, 'accuracy' => 20,
            ])->assertRedirect();

        $this->assertSame(BookingStatus::Arrived, $b->fresh()->status);
    }

    public function test_arrived_is_blocked_when_location_is_off(): void
    {
        // No fix at all (location off) → a driver cannot mark Arrived. They must
        // turn location on; this is what stops a fake "arrived" from miles away.
        $driver = $this->driver();
        $b = $this->job($driver);

        $this->actingAs($driver)
            ->post(route('driver.job.status', $b), ['status' => 'arrived'])
            ->assertRedirect()
            ->assertSessionHas('arriveError');

        $this->assertSame(BookingStatus::EnRoute, $b->fresh()->status);
    }

    public function test_set_off_is_allowed_from_anywhere_with_location_on(): void
    {
        // Set off isn't distance-checked — just needs location ON (any fix).
        $driver = $this->driver();
        $b = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Accepted->value,
            'pickup_at' => now()->addMinutes(5), 'meta' => ['geo' => ['pickup' => self::PICKUP]],
        ]);

        $this->actingAs($driver)
            ->post(route('driver.job.status', $b), [
                'status' => 'en_route', 'lat' => 51.5074, 'lng' => -0.1278, // far is fine for set off
            ])->assertRedirect();

        $this->assertSame(BookingStatus::EnRoute, $b->fresh()->status);
    }

    public function test_set_off_requires_location_too(): void
    {
        // Location is required from the get-go: set off with no fix is blocked.
        $driver = $this->driver();
        $b = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Accepted->value,
            'pickup_at' => now()->addMinutes(5),
        ]);

        $this->actingAs($driver)
            ->post(route('driver.job.status', $b), ['status' => 'en_route'])
            ->assertRedirect()
            ->assertSessionHas('arriveError');

        $this->assertSame(BookingStatus::Accepted, $b->fresh()->status);
    }

    public function test_complete_requires_location_too(): void
    {
        $driver = $this->driver();
        $b = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Collected->value,
            'pickup_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($driver)
            ->post(route('driver.job.status', $b), ['status' => 'complete'])
            ->assertRedirect()
            ->assertSessionHas('arriveError');

        $this->assertSame(BookingStatus::Collected, $b->fresh()->status);
    }

    public function test_pob_is_blocked_far_from_the_pickup(): void
    {
        // Passenger-on-board happens at the pickup — a driver rattling through the
        // statuses near the DROP-OFF can't mark it from miles away.
        $driver = $this->driver();
        $b = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Arrived->value,
            'pickup_at' => now()->subMinutes(2), 'meta' => ['geo' => ['pickup' => self::PICKUP]],
        ]);

        $this->actingAs($driver)->post(route('driver.job.status', $b), [
            'status' => 'collected', 'lat' => 51.5074, 'lng' => -0.1278, // London — far
        ])->assertRedirect()->assertSessionHas('arriveError');

        $this->assertSame(BookingStatus::Arrived, $b->fresh()->status);
    }

    public function test_pob_goes_through_at_the_pickup(): void
    {
        $driver = $this->driver();
        $b = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Arrived->value,
            'pickup_at' => now()->subMinutes(2), 'meta' => ['geo' => ['pickup' => self::PICKUP]],
        ]);

        $this->actingAs($driver)->post(route('driver.job.status', $b), [
            'status' => 'collected', 'lat' => 53.4040, 'lng' => -1.5000,
        ])->assertRedirect();

        $this->assertSame(BookingStatus::Collected, $b->fresh()->status);
    }

    public function test_pin_distance_flags_far_stamps_only_where_presence_is_expected(): void
    {
        $b = $this->job($this->driver());

        // At the pickup for arrived → within radius, not far.
        $near = $b->pinDistanceMiles(53.4040, -1.5000, 'arrived');
        $this->assertFalse($near['far']);
        $this->assertTrue($near['expects']);

        // Arrived logged from London → far.
        $far = $b->pinDistanceMiles(51.5074, -0.1278, 'arrived');
        $this->assertTrue($far['far']);

        // "On the way" is informational only — never a far/near verdict.
        $enroute = $b->pinDistanceMiles(51.5074, -0.1278, 'en_route');
        $this->assertFalse($enroute['expects']);
        $this->assertFalse($enroute['far']);
    }

    public function test_set_off_records_an_eta_to_the_pickup(): void
    {
        $driver = $this->driver();
        $b = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Accepted->value,
            'pickup_at' => now()->addMinutes(30), 'meta' => ['geo' => ['pickup' => self::PICKUP]],
        ]);

        // Set off ~30 km away → an ETA some minutes out is stored.
        $this->actingAs($driver)->post(route('driver.job.status', $b), [
            'status' => 'en_route', 'lat' => 53.6500, 'lng' => -1.5000,
        ])->assertRedirect();

        $b->refresh();
        $this->assertNotNull($b->enRouteEta());
        $this->assertGreaterThan(0, $b->enRouteDriveMinutes());
    }

    public function test_batch_updated_statuses_are_flagged(): void
    {
        $b = $this->job($this->driver()); // EnRoute
        $now = now();
        // Set off, arrived and POB all within a minute — physically impossible.
        foreach (['en_route', 'arrived', 'collected'] as $i => $s) {
            $b->statusHistory()->create(['to_status' => $s, 'created_at' => $now->copy()->addSeconds($i * 20)]);
        }

        $this->assertNotNull($b->fresh()->batchUpdateFlag());
    }

    public function test_a_blocked_tap_flags_the_office(): void
    {
        $driver = $this->driver();
        $b = $this->job($driver); // EnRoute, pickup geo set

        $this->actingAs($driver)->post(route('driver.job.status', $b), [
            'status' => 'arrived', 'lat' => 51.5074, 'lng' => -0.1278, // far
        ]);

        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $b->id, 'event_type' => 'location_blocked',
        ]);
    }
}
