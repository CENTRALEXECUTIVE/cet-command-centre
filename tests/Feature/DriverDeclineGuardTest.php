<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA 25 Nov 2026: a driver could decline a job after setting off or even after completing it. */
class DriverDeclineGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_offer_or_accepted_job_can_be_declined(): void
    {
        $driver = User::factory()->driver()->create();
        foreach ([BookingStatus::EnRoute, BookingStatus::Arrived, BookingStatus::Collected, BookingStatus::Complete] as $st) {
            $b = Booking::factory()->create(['driver_id' => $driver->id, 'status' => $st->value, 'pickup_at' => now()->addHours(2)]);
            $this->actingAs($driver)->post(route('driver.job.decline', $b))->assertRedirect();
            $this->assertSame($st, $b->fresh()->status, $st->value.' must not be declinable');
            $this->assertSame($driver->id, $b->fresh()->driver_id);
        }

        $offer = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Allocated->value, 'pickup_at' => now()->addHours(2)]);
        $this->actingAs($driver)->post(route('driver.job.decline', $offer))->assertRedirect(route('driver.jobs'));
        $this->assertSame(BookingStatus::Pending, $offer->fresh()->status);
        $this->assertNull($offer->fresh()->driver_id);
        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'unallocated', 'severity' => 'critical']);
    }
}
