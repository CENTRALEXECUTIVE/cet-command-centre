<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jobs the calendar tags with a director's name (ABDI / MAJ) — or any named
 * system driver — are auto-allocated to that driver, so the office doesn't have
 * to allocate them by hand.
 */
class AutoAllocateTaggedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class]);
    }

    private function tagged(string $tag): Booking
    {
        return Booking::factory()->create([
            'status' => BookingStatus::Pending->value,
            'driver_id' => null,
            'pickup_at' => now()->addDay(),
            'meta' => ['driver_tag' => $tag],
        ]);
    }

    public function test_an_abdi_tagged_job_is_allocated_to_abdi(): void
    {
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $booking->refresh();
        $this->assertSame($abdi->id, $booking->driver_id);
        $this->assertSame(BookingStatus::Allocated, $booking->status);
    }

    public function test_a_maj_tagged_job_is_allocated_to_maj(): void
    {
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('MAJ');

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($maj->id, $booking->fresh()->driver_id);
    }

    public function test_a_cover_or_unknown_tag_is_left_unallocated(): void
    {
        $booking = $this->tagged('COVER');

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertNull($booking->fresh()->driver_id);
    }

    public function test_a_wrong_allocation_is_corrected_to_the_calendar_tag(): void
    {
        // The calendar tags this ABDI but it's sitting on Maj — the calendar is
        // the source of truth, so it's corrected to Abdi.
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');
        $booking->forceFill(['driver_id' => $maj->id, 'status' => BookingStatus::Allocated->value])->save();

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($abdi->id, $booking->fresh()->driver_id);
    }

    public function test_it_does_not_yank_a_job_a_driver_has_already_accepted(): void
    {
        // Once a driver has accepted (or started) a job, the tag no longer moves
        // it — that's a live commitment, changed by hand if at all.
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');
        $booking->forceFill(['driver_id' => $maj->id, 'status' => BookingStatus::Accepted->value])->save();

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($maj->id, $booking->fresh()->driver_id);
    }

    public function test_the_tag_is_read_from_the_calendar_title_when_not_stored(): void
    {
        // An ETO-CSV booking never had meta['driver_tag'] written, but its linked
        // calendar event's title carries "(ABDI)" — that must still allocate.
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending->value,
            'driver_id' => null,
            'pickup_at' => now()->addDay(),
        ]);
        $booking->calendarEvent()->create([
            'calendar_id' => 'cal',
            'title' => '*Dr Stuart David Richards MAN Return (ABDI)*',
            'location' => 'x',
            'description' => 'x',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'timezone' => 'Europe/London',
        ]);

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($abdi->id, $booking->fresh()->driver_id);
    }
}
