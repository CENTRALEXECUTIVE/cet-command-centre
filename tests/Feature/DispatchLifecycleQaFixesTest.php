<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dispatch / lifecycle findings from the full-system test (25 Nov 2026). */
class DispatchLifecycleQaFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_a_job_back_to_pending_takes_the_driver_off_it(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $b = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Accepted->value, 'pickup_at' => now()->addDay()]);
        $this->actingAs($admin)->post(route('despatch.quick-status', $b), ['status' => 'pending'])->assertRedirect();
        $this->assertSame(BookingStatus::Pending, $b->fresh()->status);
        $this->assertNull($b->fresh()->driver_id);
    }

    public function test_moving_an_accepted_job_to_another_driver_makes_it_an_offer_again(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->driver()->create();
        $bDriver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $bDriver->id]);
        $job = Booking::factory()->create(['driver_id' => $a->id, 'status' => BookingStatus::Accepted->value, 'pickup_at' => now()->addDay()]);
        $this->actingAs($admin)->post(route('despatch.reassign', $job), ['driver_id' => $bDriver->id])->assertRedirect();
        $this->assertSame($bDriver->id, $job->fresh()->driver_id);
        $this->assertSame(BookingStatus::Allocated, $job->fresh()->status);
    }

    public function test_reassign_without_a_driver_field_does_not_crash(): void
    {
        $admin = User::factory()->admin()->create();
        $job = Booking::factory()->create(['status' => BookingStatus::Pending->value, 'pickup_at' => now()->addDay()]);
        $this->actingAs($admin)->post(route('despatch.reassign', $job), [])->assertRedirect();
    }

    public function test_a_driver_added_without_a_nickname_gets_a_profile_and_shows_on_dispatch(): void
    {
        $super = User::factory()->admin()->create(['is_super_admin' => true]);
        $this->actingAs($super)->post(route('users.store'), [
            'name' => 'Second Driver', 'email' => 'second@example.com', 'role' => 'driver',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ]);
        $u = User::where('email', 'second@example.com')->first();
        $this->assertNotNull($u, 'user created');
        $this->assertNotNull($u->driverProfile, 'a driver always has a profile');
    }

    public function test_pasted_party_and_luggage_are_read(): void
    {
        $r = app(\App\Services\Intake\FreeIntakeParser::class)->parse("Jane Smith 07700900123\nPickup Sheffield S1 2HH\nTo Manchester Airport\nPassengers 2, 2 large suitcases, 1 cabin bag");
        $this->assertSame([2, 2, 1], [$r['passengers'], $r['suitcases'], $r['hand_luggage']]);
    }

    public function test_an_hourly_hire_is_titled_hourly_not_as(): void
    {
        $b = Booking::factory()->create(['journey_type' => 'hourly', 'destination_address' => 'As directed (hourly hire)',
            'pickup_address' => '12 Fargate, Sheffield S1 2HE', 'meta' => ['hourly_hours' => 4]]);
        $ev = app(\App\Services\CalendarEventBuilder::class)->buildFor($b->fresh());
        $this->assertStringContainsString('HOURLY', $ev->title);
        $this->assertStringNotContainsString(' AS ', $ev->title);
    }
}
