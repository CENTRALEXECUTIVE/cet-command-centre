<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use App\Services\Watchdog\StatusWatchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA 25 Nov 2026: a job left "en route" for hours silenced every alert on the driver's next job. */
class WatchdogStuckJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forgotten_open_job_is_flagged_and_does_not_count_as_busy(): void
    {
        User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
        $driver = User::factory()->driver()->create();
        $stuck = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::EnRoute->value,
            'pickup_at' => now()->subHours(5), 'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Rotherham S60 5WG']);
        $live = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::EnRoute->value,
            'pickup_at' => now()->subMinutes(5), 'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Rotherham S60 5WG']);

        $wd = app(StatusWatchdog::class);
        $this->assertTrue($wd->isStuckOpen($stuck));
        $this->assertFalse($wd->isStuckOpen($live));

        $wd->run();
        $this->assertDatabaseHas('job_nudges', ['booking_id' => $stuck->id, 'nudge_type' => 'admin_stuck_open', 'recipient_type' => 'admin']);
        $this->assertDatabaseMissing('job_nudges', ['booking_id' => $live->id, 'nudge_type' => 'admin_stuck_open']);
    }
}
