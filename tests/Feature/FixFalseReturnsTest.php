<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Healing bookings that ETO's a/b suffix wrongly paired as a return when they are
 * two independent bookings on the same journey (same pickup). The command fixes
 * existing ones; the per-booking button fixes a single one; a genuine reversed
 * return is always left paired.
 */
class FixFalseReturnsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Booking, 1: Booking} outbound-ish, return-ish */
    private function pair(string $aPickup, string $aDrop, string $bPickup, string $bDrop): array
    {
        $a = Booking::factory()->create([
            'pickup_address' => $aPickup, 'destination_address' => $aDrop,
            'journey_type' => 'return',
        ]);
        $b = Booking::factory()->create([
            'pickup_address' => $bPickup, 'destination_address' => $bDrop,
            'journey_type' => 'return', 'is_return_leg' => true, 'linked_booking_id' => $a->id,
        ]);
        $a->forceFill(['linked_booking_id' => $b->id])->save();

        return [$a->fresh(), $b->fresh()];
    }

    public function test_the_command_unlinks_a_same_journey_false_return(): void
    {
        // Janine & Sean: BOTH Manchester Airport → Worrygoose (same pickup).
        [$a, $b] = $this->pair(
            'Manchester Airport (MAN), Terminal 2', '2 Worrygoose Lane, Rotherham',
            'Manchester Airport (MAN), Terminal 2', '2 Worrygoose Lane, Rotherham',
        );

        $this->artisan('cet:fix-false-returns')->assertSuccessful();

        $this->assertFalse((bool) $b->fresh()->is_return_leg);
        $this->assertNull($b->fresh()->linked_booking_id);
        $this->assertNull($a->fresh()->linked_booking_id);
        $this->assertSame('one_way', $b->fresh()->journey_type);
    }

    public function test_the_command_leaves_a_genuine_reversed_return_paired(): void
    {
        // Real return: MAN → home, then home → MAN (reversed).
        [$a, $b] = $this->pair(
            'Manchester Airport (MAN), Terminal 2', '2 Worrygoose Lane, Rotherham',
            '2 Worrygoose Lane, Rotherham', 'Manchester Airport (MAN), Terminal 2',
        );

        $this->artisan('cet:fix-false-returns')->assertSuccessful();

        $this->assertTrue((bool) $b->fresh()->is_return_leg, 'genuine return stays paired');
        $this->assertSame($a->id, $b->fresh()->linked_booking_id);
    }

    public function test_the_unlink_button_fixes_a_single_booking(): void
    {
        $admin = User::factory()->admin()->create();
        [$a, $b] = $this->pair(
            'Manchester Airport (MAN)', 'Worrygoose Lane',
            'Manchester Airport (MAN)', 'Worrygoose Lane',
        );

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()->assertSee('Not a return', false);

        $this->actingAs($admin)->post(route('bookings.unlink-return', $b))->assertRedirect();

        $this->assertFalse((bool) $b->fresh()->is_return_leg);
        $this->assertNull($a->fresh()->linked_booking_id);
    }

    public function test_unlink_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        [, $b] = $this->pair('A', 'B', 'A', 'B');
        $this->actingAs($driver)->post(route('bookings.unlink-return', $b))->assertForbidden();
    }
}
