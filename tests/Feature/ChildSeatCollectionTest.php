<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\JobNudge;
use App\Models\User;
use App\Services\Push\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A job carrying a child/booster/infant seat: the driver must collect the seat
 * from the office and confirm it on their link; until they do, the office is
 * nudged so it isn't forgotten.
 */
class ChildSeatCollectionTest extends TestCase
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

    private function seatBooking(BookingStatus $status, Carbon $pickup): Booking
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        $booking = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => $status,
            'pickup_at' => $pickup, 'passengers' => 2,
        ]);
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['child_seats' => 1])])->save();

        return $booking->fresh();
    }

    public function test_the_driver_link_shows_a_child_seat_reminder_and_confirms_it(): void
    {
        $booking = $this->seatBooking(BookingStatus::Allocated, now()->addHours(3));
        $token = $booking->driverLinkToken();

        // The reminder shows on the driver's link.
        $this->get(route('driver.link', $token))->assertOk()
            ->assertSee('Collect the child seat')
            ->assertSee('I’ve collected the child seat', false);

        // Driver confirms.
        $this->post(route('driver.link.child-seats', $token))->assertRedirect();

        $booking->refresh();
        $this->assertTrue($booking->childSeatsCollected());
        $this->assertNotNull($booking->childSeatsCollectedAt());

        // Now the link shows it's confirmed, not the collect prompt.
        $this->get(route('driver.link', $token))->assertOk()
            ->assertSee('Child seat collected')
            ->assertDontSee('I’ve collected the child seat', false);
    }

    public function test_the_admin_booking_page_shows_the_collection_status(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->seatBooking(BookingStatus::Allocated, now()->addHours(3));

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('not collected');

        $booking->confirmChildSeatsCollected();
        $this->actingAs($admin)->get(route('bookings.show', $booking->fresh()))->assertOk()
            ->assertSee('collected ✓', false);
    }

    public function test_the_office_is_nudged_when_the_seat_is_not_confirmed_near_pickup(): void
    {
        $admin = User::factory()->admin()->create();
        // Pickup 90 min out (inside the 2h window), driver assigned, not confirmed.
        $booking = $this->seatBooking(BookingStatus::Allocated, now()->addMinutes(90));

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        $this->assertSame(1, JobNudge::where('booking_id', $booking->id)
            ->where('nudge_type', 'admin_child_seats')->count());
        $titles = array_column($this->push->to($admin), 'title');
        $this->assertNotEmpty(array_filter($titles, fn ($t) => str_contains($t, 'Child seat not confirmed')));
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id, 'event_type' => 'admin_child_seats', 'severity' => 'warning',
        ]);
    }

    public function test_no_office_nudge_once_the_seat_is_confirmed(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->seatBooking(BookingStatus::Allocated, now()->addMinutes(90));
        $booking->confirmChildSeatsCollected();

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        $this->assertSame(0, JobNudge::where('booking_id', $booking->id)
            ->where('nudge_type', 'admin_child_seats')->count());
        $this->assertCount(0, $this->push->to($admin));
    }

    public function test_a_far_off_job_does_not_nudge_the_office_yet(): void
    {
        $admin = User::factory()->admin()->create();
        // Pickup 3h out — outside the 2h window.
        $this->seatBooking(BookingStatus::Allocated, now()->addHours(3));

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        $this->assertDatabaseMissing('job_nudges', ['nudge_type' => 'admin_child_seats']);
    }

    public function test_a_job_with_no_child_seat_never_nudges(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Allocated->value,
            'pickup_at' => now()->addMinutes(90), 'passengers' => 2,
        ]);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        $this->assertDatabaseMissing('job_nudges', ['nudge_type' => 'admin_child_seats']);
    }
}
