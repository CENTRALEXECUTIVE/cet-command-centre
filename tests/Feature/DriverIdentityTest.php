<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CoverDriver;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\DriverRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Driver identity: same-first-name/different-surname stays separate, the
 * "known as" nickname (office-only, never customer-facing), and auto-assigning
 * the driver the calendar already names.
 */
class DriverIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_name_attaches_to_the_right_person_not_a_shared_first_name(): void
    {
        // Two real drivers who share a first name.
        $ali = User::factory()->driver()->create(['name' => 'Hamza Ali', 'email' => 'ali@example.com']);
        DriverProfile::create(['user_id' => $ali->id]);
        $khan = User::factory()->driver()->create(['name' => 'Hamza Khan', 'email' => 'khan@example.com']);
        DriverProfile::create(['user_id' => $khan->id]);

        // Adding "Hamza Khan" to the directory attaches to Khan, never Ali.
        $matched = app(DriverRosterService::class)->ensureUser(CoverDriver::create(['name' => 'Hamza Khan', 'is_active' => true]));

        $this->assertSame($khan->id, $matched->id);
    }

    public function test_a_bare_first_name_does_not_merge_two_people(): void
    {
        // Two real drivers share the first name and have no callsign to tell apart.
        $ali = User::factory()->driver()->create(['name' => 'Hamza Ali', 'email' => 'ali@example.com']);
        DriverProfile::create(['user_id' => $ali->id]);
        $khan = User::factory()->driver()->create(['name' => 'Hamza Khan', 'email' => 'khan@example.com']);
        DriverProfile::create(['user_id' => $khan->id]);

        // A bare "Hamza" is ambiguous → it must NOT be merged into either of them.
        $made = app(DriverRosterService::class)->ensureUser(CoverDriver::create(['name' => 'Hamza', 'is_active' => true]));

        $this->assertNotSame($ali->id, $made->id);
        $this->assertNotSame($khan->id, $made->id);
    }

    public function test_a_lone_first_name_still_attaches_to_the_only_match(): void
    {
        // Just one real Hamza → a bare "Hamza" attaches to them (unambiguous).
        $ali = User::factory()->driver()->create(['name' => 'Hamza Ali', 'email' => 'ali@example.com']);
        DriverProfile::create(['user_id' => $ali->id]);

        $again = app(DriverRosterService::class)->ensureUser(CoverDriver::create(['name' => 'Hamza', 'is_active' => true]));

        $this->assertSame($ali->id, $again->id);
    }

    public function test_nickname_is_office_only_and_reminders_use_the_real_name(): void
    {
        $admin = User::factory()->admin()->create();

        // Create a driver with a "known as" nickname via user management.
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Hamza Ali', 'nickname' => 'Hamza E Class',
            'email' => 'hamza@example.com', 'role' => 'driver',
        ])->assertRedirect();

        $driver = User::where('email', 'hamza@example.com')->first();
        $this->assertSame('Hamza E Class', $driver->nickname());
        $this->assertSame('Hamza E Class', $driver->knownAs());
        $this->assertSame('Hamza Ali (Hamza E Class)', $driver->nameWithNickname());

        // A reminder for a job with this driver uses the REAL name, never the nickname.
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => now()->addDay()]);
        $booking->forceFill(['meta' => ['driver_details' => ['name' => $driver->name, 'phone' => '07700900000']]])->save();
        $body = app(\App\Services\Messaging\BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringContainsString('Hamza Ali', $body);
        $this->assertStringNotContainsString('E Class', $body);
    }

    public function test_the_calendar_driver_is_auto_assigned(): void
    {
        // A driver the calendar tag will resolve to.
        $driver = User::factory()->driver()->create(['name' => 'Hamza Ali']);
        DriverProfile::create(['user_id' => $driver->id, 'callsign' => 'Hamza']);

        $booking = Booking::factory()->create(['driver_id' => null, 'status' => BookingStatus::Pending]);
        $booking->forceFill(['meta' => ['driver_tag' => 'Hamza']])->save();

        $this->assertTrue($booking->autoAssignDriverFromCalendarTag());

        $booking = $booking->fresh();
        $this->assertSame($driver->id, $booking->driver_id);
        $this->assertSame(BookingStatus::Allocated, $booking->status);
    }

    public function test_auto_assign_never_overrides_an_existing_driver(): void
    {
        $a = User::factory()->driver()->create(['name' => 'Hamza Ali']);
        DriverProfile::create(['user_id' => $a->id, 'callsign' => 'Hamza']);
        $existing = User::factory()->driver()->create(['name' => 'Someone Else']);

        $booking = Booking::factory()->create(['driver_id' => $existing->id]);
        $booking->forceFill(['meta' => ['driver_tag' => 'Hamza']])->save();

        $this->assertFalse($booking->autoAssignDriverFromCalendarTag());
        $this->assertSame($existing->id, $booking->fresh()->driver_id);
    }

    public function test_an_unknown_tag_assigns_nobody(): void
    {
        $booking = Booking::factory()->create(['driver_id' => null]);
        $booking->forceFill(['meta' => ['driver_tag' => 'COVER']])->save();

        $this->assertFalse($booking->autoAssignDriverFromCalendarTag());
        $this->assertNull($booking->fresh()->driver_id);
    }
}
