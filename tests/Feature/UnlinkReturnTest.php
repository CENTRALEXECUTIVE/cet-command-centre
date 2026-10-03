<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual "Not a return — unlink" control: for the rare case where ETO's a/b suffix
 * linked two bookings that aren't actually a return. Both bookings stay; only the
 * pairing is removed. Admin only.
 */
class UnlinkReturnTest extends TestCase
{
    use RefreshDatabase;

    private function pairedReturn(): array
    {
        $a = Booking::factory()->create(['journey_type' => 'return']);
        $b = Booking::factory()->create([
            'journey_type' => 'return', 'is_return_leg' => true, 'linked_booking_id' => $a->id,
        ]);
        $a->forceFill(['linked_booking_id' => $b->id])->save();

        return [$a->fresh(), $b->fresh()];
    }

    public function test_the_button_unlinks_both_legs(): void
    {
        $admin = User::factory()->admin()->create();
        [$a, $b] = $this->pairedReturn();

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()->assertSee('Not a return', false);
        $this->actingAs($admin)->post(route('bookings.unlink-return', $b))->assertRedirect();

        $this->assertFalse((bool) $b->fresh()->is_return_leg);
        $this->assertNull($b->fresh()->linked_booking_id);
        $this->assertNull($a->fresh()->linked_booking_id);
        $this->assertSame('one_way', $b->fresh()->journey_type);
    }

    public function test_unlink_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        [, $b] = $this->pairedReturn();
        $this->actingAs($driver)->post(route('bookings.unlink-return', $b))->assertForbidden();
    }
}
