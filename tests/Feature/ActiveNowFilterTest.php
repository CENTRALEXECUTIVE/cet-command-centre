<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard "Active now" tile must open a list that actually shows the
 * active jobs it counted — including one whose pickup was late last night and
 * is still running (the case the dispatch board's "today only" view missed).
 */
class ActiveNowFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_filter_shows_a_job_that_crossed_midnight(): void
    {
        Carbon::setTestNow('2026-08-15 01:30:00'); // 1:30am
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();

        // Picked up at 11:45pm yesterday, still on the way — active now.
        $active = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Collected,
            'pickup_at' => Carbon::parse('2026-08-14 23:45:00'),
        ]);

        $res = $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'active']))->assertOk();
        $this->assertTrue($res->viewData('bookings')->contains(fn ($b) => $b->id === $active->id));

        Carbon::setTestNow();
    }

    public function test_active_filter_excludes_finished_and_stale_jobs(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();

        $done = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Complete, 'pickup_at' => now()->subHour()]);
        $stale = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Collected, 'pickup_at' => now()->subDays(3)]);
        $live = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::EnRoute, 'pickup_at' => now()->subMinutes(20)]);

        $ids = $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'active']))
            ->assertOk()->viewData('bookings')->pluck('id');

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($done->id, $ids);
        $this->assertNotContains($stale->id, $ids);

        Carbon::setTestNow();
    }
}
