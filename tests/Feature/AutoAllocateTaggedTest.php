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

    public function test_an_existing_driver_is_never_overridden_by_the_tag(): void
    {
        // The calendar tags this ABDI, but the office put it on Maj (e.g. a
        // last-minute cover). The tag must NEVER pull it back — an existing driver
        // always stands, whether or not a lock flag was ever set.
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');
        $booking->forceFill(['driver_id' => $maj->id, 'status' => BookingStatus::Allocated->value])->save();

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($maj->id, $booking->fresh()->driver_id);
    }

    public function test_it_does_not_touch_a_job_a_driver_has_already_accepted(): void
    {
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');
        $booking->forceFill(['driver_id' => $maj->id, 'status' => BookingStatus::Accepted->value])->save();

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($maj->id, $booking->fresh()->driver_id);
    }

    public function test_a_driver_i_chose_by_hand_is_never_reverted_by_the_tag(): void
    {
        // Job tagged ABDI, but the boss deliberately reassigned it to Maj in the
        // app. That choice is locked — the calendar tag must NOT move it back.
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');
        $booking->forceFill(['driver_id' => $maj->id, 'status' => BookingStatus::Allocated->value])->save();
        $booking->lockDriverChoice();

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        $this->assertSame($maj->id, $booking->fresh()->driver_id);
    }

    public function test_reassigning_in_the_app_locks_the_driver_against_the_tag(): void
    {
        $admin = User::factory()->admin()->create();
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');

        // The boss reassigns to Maj through the dispatch board.
        $this->actingAs($admin)
            ->post(route('despatch.reassign', $booking), ['driver_id' => $maj->id])
            ->assertRedirect();

        $this->assertSame($maj->id, $booking->fresh()->driver_id);
        $this->assertNotEmpty($booking->fresh()->meta['driver_locked'] ?? null);

        // The tag reconcile now leaves it alone.
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
