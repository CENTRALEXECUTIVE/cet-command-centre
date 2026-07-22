<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manual "merge duplicate" button: two records of the same journey that
 * carry DIFFERENT references (so the auto-dedupe leaves them) can be folded into
 * one by hand — keep this copy, the other's driver/tips/reference move across.
 */
class BookingMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(array $attrs = []): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create($attrs);
    }

    public function test_admin_can_merge_a_same_journey_twin_into_the_kept_copy(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $customer = Customer::factory()->create(['phone' => '+447700900123']);
        $at = now()->addDay()->setTime(13, 45);

        // Keep = the unassigned copy; dupe = the one with the driver + tip + ref.
        $keep = $this->booking([
            'customer_id' => $customer->id, 'pickup_at' => $at,
            'external_reference' => null, 'driver_id' => null,
        ]);
        $dupe = $this->booking([
            'customer_id' => $customer->id, 'pickup_at' => $at,
            'external_reference' => 'CET-509508', 'driver_id' => $driver->id,
        ]);
        $dupe->logTip(10.0, 'card');

        $this->actingAs($admin)
            ->post(route('bookings.merge', $keep), ['dupe_id' => $dupe->id])
            ->assertRedirect(route('bookings.show', $keep));

        // One record left, carrying the driver, the tip and the reference.
        $this->assertNull($dupe->fresh());
        $survivor = $keep->fresh();
        $this->assertSame($driver->id, $survivor->driver_id);
        $this->assertSame('CET-509508', $survivor->external_reference);
        $this->assertSame(10.0, $survivor->tipsTotal());
    }

    public function test_merging_leaves_only_one_queued_reminder(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create(['phone' => '+447700900123']);
        $at = now()->addDay()->setTime(13, 45);

        $keep = $this->booking(['customer_id' => $customer->id, 'pickup_at' => $at]);
        $dupe = $this->booking(['customer_id' => $customer->id, 'pickup_at' => $at]);

        // Each copy carries its own queued 24h reminder — the cause of the
        // doubled-up WhatsApp reminder after a merge.
        foreach ([$keep, $dupe] as $b) {
            \App\Models\Message::create([
                'booking_id' => $b->id, 'customer_id' => $customer->id,
                'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'reminder_24h',
                'to_address' => $customer->phone, 'body' => 'reminder', 'status' => 'queued',
                'scheduled_for' => $at->copy()->subDay(),
            ]);
        }

        $this->actingAs($admin)
            ->post(route('bookings.merge', $keep), ['dupe_id' => $dupe->id])
            ->assertRedirect(route('bookings.show', $keep));

        // Exactly one queued reminder remains, on the survivor.
        $this->assertNull($dupe->fresh());
        $this->assertSame(1, \App\Models\Message::where('type', 'reminder_24h')->where('status', 'queued')->count());
        $this->assertSame(1, $keep->messages()->where('type', 'reminder_24h')->count());
    }

    public function test_it_refuses_to_merge_two_unrelated_bookings(): void
    {
        $admin = User::factory()->admin()->create();

        // Different customers + times → not the same journey → rejected.
        $keep = $this->booking(['pickup_at' => now()->addDay()->setTime(9, 0)]);
        $other = $this->booking(['pickup_at' => now()->addDays(3)->setTime(16, 0)]);

        $this->actingAs($admin)
            ->post(route('bookings.merge', $keep), ['dupe_id' => $other->id])
            ->assertSessionHasErrors('dupe_id');

        // Both still there.
        $this->assertNotNull($keep->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_a_non_admin_cannot_merge(): void
    {
        $driver = User::factory()->driver()->create();
        $customer = Customer::factory()->create(['phone' => '+447700900123']);
        $at = now()->addDay()->setTime(13, 45);
        $keep = $this->booking(['customer_id' => $customer->id, 'pickup_at' => $at]);
        $dupe = $this->booking(['customer_id' => $customer->id, 'pickup_at' => $at]);

        $this->actingAs($driver)
            ->post(route('bookings.merge', $keep), ['dupe_id' => $dupe->id])
            ->assertForbidden();

        $this->assertNotNull($dupe->fresh());
    }

    public function test_the_page_shows_a_merge_button_for_a_twin(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create(['phone' => '+447700900123']);
        $at = now()->addDay()->setTime(13, 45);
        $keep = $this->booking([
            'customer_id' => $customer->id, 'pickup_at' => $at, 'status' => BookingStatus::Pending,
        ]);
        $this->booking([
            'customer_id' => $customer->id, 'pickup_at' => $at, 'status' => BookingStatus::Allocated,
        ]);

        $this->actingAs($admin)->get(route('bookings.show', $keep))
            ->assertOk()
            ->assertSee('Possible duplicate booking')
            ->assertSee('Merge into this one');
    }
}
