<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bookings list, Nov 2026: "From 22/10" with To left blank showed only the 22nd
 * — it looked like bookings were missing. A blank end is open ("onwards"), and
 * the range can be by pickup OR by when the booking came in.
 */
class BookingsDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_with_no_to_means_from_that_date_onwards(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        $before = Booking::factory()->create(['pickup_at' => '2026-10-21 10:00']);
        $on = Booking::factory()->create(['pickup_at' => '2026-10-22 10:00']);
        $later = Booking::factory()->create(['pickup_at' => '2026-10-30 10:00']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('bookings.index', ['from' => '2026-10-22']))
            ->assertOk()
            ->assertSee($on->reference)
            ->assertSee($later->reference)
            ->assertDontSee($before->reference)
            ->assertSee('onwards');
    }

    public function test_by_booked_date_lists_what_came_in_regardless_of_pickup(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        $cameIn = Booking::factory()->create(['pickup_at' => '2026-12-20 10:00', 'created_at' => '2026-10-28 09:00']);
        $old = Booking::factory()->create(['pickup_at' => '2026-10-29 10:00', 'created_at' => '2026-10-10 09:00']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('bookings.index', ['from' => '2026-10-22', 'by' => 'created']))
            ->assertOk()
            ->assertSee($cameIn->reference)
            ->assertDontSee($old->reference)
            ->assertSee('booked (came in)');
    }
}
