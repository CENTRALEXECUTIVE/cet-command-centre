<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The driver job screen only ever offers a driver their FORWARD actions
 * (accept → on my way → arrived → POB → completed). Pending / Cancel / No Show
 * are office-only — a driver must never see them (tapping the old "Pending"
 * button errored). An admin viewing their own job gets those in a separate
 * "Office controls" row, not mixed into the driver's taps.
 */
class DriverJobActionsTest extends TestCase
{
    use RefreshDatabase;

    private function allocatedJobFor(User $user): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $user->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addHours(3),
        ]);
    }

    public function test_a_driver_sees_only_accept_not_the_office_actions(): void
    {
        $driver = User::factory()->driver()->create();
        $job = $this->allocatedJobFor($driver);

        $this->actingAs($driver)->get(route('driver.job', $job))
            ->assertOk()
            ->assertSee('Accept this job')
            ->assertDontSee('Office controls')
            ->assertDontSee('No Show')
            ->assertDontSee('Send back to Pending');
    }

    public function test_an_admin_on_their_own_job_gets_a_separate_office_controls_row(): void
    {
        // Abdi/Maj are admins who are also the allocated driver on their own jobs.
        $admin = User::factory()->admin()->create();
        $job = $this->allocatedJobFor($admin);

        $this->actingAs($admin)->get(route('driver.job', $job))
            ->assertOk()
            ->assertSee('Accept this job')     // still the driver's forward action
            ->assertSee('Office controls')     // office-only block present
            ->assertSee('No Show')
            ->assertSee('Send back to Pending');
    }

    public function test_the_driver_pending_action_is_gone_so_it_cannot_error(): void
    {
        // An accepted job's only driver-forward step is "On My Way" — never Pending.
        $driver = User::factory()->driver()->create();
        $job = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addHours(3),
        ]);

        $this->actingAs($driver)->get(route('driver.job', $job))
            ->assertOk()
            ->assertSee('On My Way')
            ->assertDontSee('Office controls');
    }
}
