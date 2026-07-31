<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAppTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);

        $this->driver = User::factory()->driver()->create(['name' => 'Test Driver']);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_third_party' => true, 'is_available' => true]);
    }

    private function jobFor(User $driver, array $overrides = []): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(array_merge(['driver_id' => $driver->id], $overrides));
    }

    public function test_driver_sees_only_their_jobs_for_today(): void
    {
        $mine = $this->jobFor($this->driver, ['pickup_at' => today()->addHours(3), 'status' => BookingStatus::Allocated]);
        $someoneElse = $this->jobFor(User::factory()->driver()->create(), ['pickup_at' => today()->addHours(3)]);

        $this->actingAs($this->driver)->get(route('driver.jobs', ['filter' => 'today']))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($someoneElse->reference);
    }

    public function test_driver_can_accept_and_go_en_route_capturing_gps(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900999']);
        $job = $this->jobFor($this->driver, [
            'customer_id' => $customer->id,
            'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addHours(2),
        ]);

        $this->actingAs($this->driver)
            ->post(route('driver.job.status', $job), ['status' => 'en_route', 'lat' => 53.3811, 'lng' => -1.4701])
            ->assertRedirect();

        $job->refresh();
        $this->assertEquals(BookingStatus::EnRoute, $job->status);

        // GPS captured in the audit trail.
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $job->id, 'to_status' => 'en_route',
        ]);
        $history = $job->statusHistory()->where('to_status', 'en_route')->first();
        $this->assertEquals('53.3811000', $history->gps_latitude);

        // En Route side effects: tracking link + customer message.
        $this->assertNotNull($job->trackingLink);
        $this->assertDatabaseHas('messages', ['booking_id' => $job->id, 'type' => 'tracking_link']);

        // The office gets a "driver has set off" ping in the alerts feed.
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $job->id, 'event_type' => 'driver_set_off']);
    }

    public function test_office_is_pinged_on_arrived_pob_and_complete(): void
    {
        $admin = User::factory()->admin()->create();
        $job = $this->jobFor($this->driver, ['status' => BookingStatus::EnRoute, 'pickup_at' => now()->addMinutes(10)]);
        $svc = app(\App\Services\BookingStatusService::class);

        $svc->forceTransition($job, BookingStatus::Arrived, $admin);
        $svc->forceTransition($job->fresh(), BookingStatus::Collected, $admin);
        $svc->forceTransition($job->fresh(), BookingStatus::Complete, $admin);

        foreach (['driver_arrived', 'driver_on_board', 'driver_complete'] as $event) {
            $this->assertDatabaseHas('watchdog_events', ['booking_id' => $job->id, 'event_type' => $event]);
        }
    }

    public function test_a_past_job_marked_up_later_does_not_ping_the_office(): void
    {
        // Admin retroactively marks up a job from two days ago — not live, so it
        // must NOT buzz the office (only today's + future jobs do).
        $admin = User::factory()->admin()->create();
        $job = $this->jobFor($this->driver, ['status' => BookingStatus::EnRoute, 'pickup_at' => now()->subDays(2)]);
        $svc = app(\App\Services\BookingStatusService::class);

        $svc->forceTransition($job, BookingStatus::Arrived, $admin);
        $svc->forceTransition($job->fresh(), BookingStatus::Collected, $admin);
        $svc->forceTransition($job->fresh(), BookingStatus::Complete, $admin);

        foreach (['driver_arrived', 'driver_on_board', 'driver_complete'] as $event) {
            $this->assertDatabaseMissing('watchdog_events', ['booking_id' => $job->id, 'event_type' => $event]);
        }
    }

    public function test_driver_cannot_update_another_drivers_job(): void
    {
        $job = $this->jobFor(User::factory()->driver()->create(), ['status' => BookingStatus::Accepted]);

        $this->actingAs($this->driver)
            ->post(route('driver.job.status', $job), ['status' => 'en_route'])
            ->assertForbidden();
    }

    public function test_a_via_stop_shows_on_the_job_and_guides_the_driver_through_it(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900222']);
        $job = $this->jobFor($this->driver, [
            'customer_id' => $customer->id,
            'status' => BookingStatus::Collected, // passenger already on board at pickup
            'pickup_at' => now()->addHours(1),
            'destination_address' => 'Manchester Airport (MAN)',
        ]);
        \App\Models\BookingStop::create(['booking_id' => $job->id, 'sequence' => 1, 'address' => '10 Ecclesall Road, Sheffield']);

        // The job screen shows the stop and, since POB is done, guides to it —
        // Complete is NOT offered yet.
        $this->actingAs($this->driver)->get(route('driver.job', $job))
            ->assertOk()
            ->assertSee('10 Ecclesall Road, Sheffield')
            ->assertSee('Travel to the next stop')
            ->assertSee('Reached stop 1 — Passenger On Board')
            ->assertDontSee('Completed (final drop-off)');

        // Tap through the stop → counter advances, now Complete is offered.
        $this->actingAs($this->driver)->post(route('driver.job.reach-stop', $job))->assertRedirect();
        $this->assertEquals(1, $job->fresh()->stopsReached());

        $this->actingAs($this->driver)->get(route('driver.job', $job))
            ->assertOk()
            ->assertSee('Completed (final drop-off)')
            ->assertDontSee('Travel to the next stop');

        // And Complete still works from there.
        $this->actingAs($this->driver)->post(route('driver.job.status', $job), ['status' => 'complete'])->assertRedirect();
        $this->assertEquals(BookingStatus::Complete, $job->fresh()->status);
    }

    public function test_via_stops_come_from_eto_via_when_there_is_no_stop_row(): void
    {
        $job = $this->jobFor($this->driver, [
            'status' => BookingStatus::Collected,
            'pickup_at' => now()->addHours(1),
            'meta' => ['eto_via' => 'Sheffield Train Station'],
        ]);

        $this->assertSame(['Sheffield Train Station'], $job->viaStops());
        $this->actingAs($this->driver)->get(route('driver.job', $job))
            ->assertOk()->assertSee('Sheffield Train Station');
    }

    public function test_public_tracking_page_renders_via_token(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $job = $this->jobFor($this->driver, [
            'customer_id' => $customer->id,
            'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addHours(2),
        ]);
        $this->actingAs($this->driver)->post(route('driver.job.status', $job), ['status' => 'en_route']);

        $token = $job->fresh()->trackingLink->token;

        $this->get(route('track', $token))->assertOk()->assertSee('on the way');
    }
}
