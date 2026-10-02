<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk actions on the bookings list — select several and delete them at once.
 */
class BookingBulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_bulk_delete_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Booking::factory()->create();
        $b = Booking::factory()->create();
        $c = Booking::factory()->create();

        $this->actingAs($admin)->post(route('bookings.bulk'), [
            'action' => 'delete', 'ids' => $a->id.','.$b->id,
        ])->assertRedirect();

        $this->assertNull(Booking::find($a->id));   // soft-deleted
        $this->assertNull(Booking::find($b->id));
        $this->assertNotNull(Booking::find($c->id)); // untouched
        // Recoverable.
        $this->assertNotNull(Booking::withTrashed()->find($a->id));
    }

    public function test_the_select_button_shows_for_admins(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::factory()->create();

        $this->actingAs($admin)->get(route('bookings.index'))->assertOk()->assertSee('☑ Select');
    }

    public function test_a_driver_cannot_bulk_delete(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $a = Booking::factory()->create();

        $this->actingAs($driver)->post(route('bookings.bulk'), ['action' => 'delete', 'ids' => (string) $a->id])
            ->assertForbidden();
        $this->assertNotNull(Booking::find($a->id));
    }
}
