<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupeBookingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function make(string $ref, array $attrs = []): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(array_merge(['external_reference' => $ref], $attrs));
    }

    public function test_it_removes_bookings_that_share_an_eto_reference(): void
    {
        // Same reference, DIFFERENT pickup times (customer rescheduled) — still one booking.
        $a = $this->make('U6NAEK', ['pickup_at' => now()->addDay()->setTime(9, 0)]);
        $b = $this->make('U6NAEK', ['pickup_at' => now()->addDay()->setTime(11, 30)]);
        $other = $this->make('DIFFREF', ['pickup_at' => now()->addDay()->setTime(9, 0)]);

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        // One of the U6NAEK pair remains; the unrelated booking is untouched.
        $this->assertSame(1, Booking::where('external_reference', 'U6NAEK')->count());
        $this->assertNotNull($other->fresh());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->make('U6NAEK');
        $this->make('U6NAEK');

        $this->artisan('cet:dedupe-bookings --dry-run')->assertSuccessful();

        $this->assertSame(2, Booking::where('external_reference', 'U6NAEK')->count());
    }

    public function test_it_keeps_the_copy_holding_tips(): void
    {
        $plain = $this->make('U6NAEK');
        $withTip = $this->make('U6NAEK');
        $withTip->logTip(5.0, 'card');

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        // The tip-carrying copy is the one that survives.
        $survivor = Booking::where('external_reference', 'U6NAEK')->sole();
        $this->assertSame($withTip->id, $survivor->id);
        $this->assertSame(5.0, $survivor->tipsTotal());
    }

    public function test_bookings_without_a_reference_are_left_alone(): void
    {
        Booking::factory()->count(2)->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['external_reference' => null]);

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        $this->assertSame(2, Booking::whereNull('external_reference')->count());
    }
}
