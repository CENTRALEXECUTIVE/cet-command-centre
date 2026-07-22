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

    private function make(?string $ref, array $attrs = []): Booking
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

    public function test_it_keeps_the_copy_that_has_a_driver_assigned(): void
    {
        $driver = \App\Models\User::factory()->driver()->create();
        $unworked = $this->make('U6NAEK');
        $worked = $this->make('U6NAEK', ['driver_id' => $driver->id]);

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        $survivor = Booking::where('external_reference', 'U6NAEK')->sole();
        $this->assertSame($worked->id, $survivor->id);
    }

    public function test_it_merges_everything_useful_into_the_single_survivor(): void
    {
        $driver = \App\Models\User::factory()->driver()->create();

        // One copy has the driver; the other has the price and a tip. After the
        // merge there must be ONE record carrying all three — nothing lost.
        $this->make('U6NAEK', ['driver_id' => $driver->id, 'final_price' => null]);
        $withMoney = $this->make('U6NAEK', ['driver_id' => null, 'final_price' => 120]);
        $withMoney->logTip(7.5, 'card');

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        $survivor = Booking::where('external_reference', 'U6NAEK')->sole(); // exactly one left
        $this->assertSame($driver->id, $survivor->driver_id);          // driver folded in
        $this->assertSame('120.00', (string) $survivor->final_price);  // price kept
        $this->assertSame(7.5, $survivor->tipsTotal());                // tip moved across
    }

    public function test_bookings_without_a_reference_are_left_alone(): void
    {
        // Different customers → different journeys → untouched.
        Booking::factory()->count(2)->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['external_reference' => null]);

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        $this->assertSame(2, Booking::whereNull('external_reference')->count());
    }

    public function test_it_merges_a_no_reference_copy_into_the_calendar_copy(): void
    {
        // The classic "two on the Command Centre, one on the calendar": same
        // customer, same pickup minute — one has the ETO ref (from the calendar
        // import), one has none (the old paste-a-booking copy). They never share
        // a reference, so only the journey match catches them.
        $customer = \App\Models\Customer::factory()->create(['phone' => '+447700900123']);
        $at = now()->addDay()->setTime(14, 30);

        $noRef = $this->make(null, ['customer_id' => $customer->id, 'pickup_at' => $at]);
        $withRef = $this->make('U6NAEK', ['customer_id' => $customer->id, 'pickup_at' => $at]);
        $withRef->logTip(5.0, 'card'); // richer copy → the survivor

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        // One record left, carrying the reference and the money.
        $survivor = Booking::where('customer_id', $customer->id)->sole();
        $this->assertSame($withRef->id, $survivor->id);
        $this->assertSame('U6NAEK', $survivor->external_reference);
        $this->assertNull($noRef->fresh());
    }

    public function test_it_never_merges_two_different_references_in_the_same_slot(): void
    {
        // Same customer + minute but TWO different ETO references = a genuine
        // re-book that isn't on the calendar yet. By rule these stay separate.
        $customer = \App\Models\Customer::factory()->create(['phone' => '+447700900999']);
        $at = now()->addDay()->setTime(9, 15);

        $this->make('OLDREF', ['customer_id' => $customer->id, 'pickup_at' => $at]);
        $this->make('NEWREF', ['customer_id' => $customer->id, 'pickup_at' => $at]);

        $this->artisan('cet:dedupe-bookings')->assertSuccessful();

        $this->assertSame(2, Booking::where('customer_id', $customer->id)->count());
    }
}
