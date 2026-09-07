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

    public function test_it_never_overrides_an_existing_driver(): void
    {
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = $this->tagged('ABDI');
        $booking->forceFill(['driver_id' => $maj->id, 'status' => BookingStatus::Allocated->value])->save();

        $this->artisan('cet:auto-allocate-tagged')->assertSuccessful();

        // Stays with Maj — the tag never overrides an assignment already made.
        $this->assertSame($maj->id, $booking->fresh()->driver_id);
    }
}
