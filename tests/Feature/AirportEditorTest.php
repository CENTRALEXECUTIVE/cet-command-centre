<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_the_airports_page(): void
    {
        $admin = User::factory()->admin()->create();
        Airport::create(['code' => 'MAN', 'name' => 'Manchester Airport', 'is_active' => true]);

        $this->actingAs($admin)->get(route('airports.index'))->assertOk()
            ->assertSee('Airports')->assertSee('Manchester Airport');
    }

    public function test_admin_can_add_an_airport(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('airports.store'), [
            'code' => 'bhx', 'name' => 'Birmingham Airport', 'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('airports', ['code' => 'BHX', 'name' => 'Birmingham Airport', 'is_active' => true]);
    }

    public function test_admin_can_edit_an_airport(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Airport::create(['code' => 'MAN', 'name' => 'Manchester', 'is_active' => true]);

        $this->actingAs($admin)->put(route('airports.update', $a), [
            'code' => 'MAN', 'name' => 'Manchester Airport', 'is_general_pool' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('airports', ['id' => $a->id, 'name' => 'Manchester Airport', 'is_general_pool' => true, 'is_active' => false]);
    }

    public function test_an_airport_with_bookings_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Airport::create(['code' => 'MAN', 'name' => 'Manchester', 'is_active' => true]);
        Booking::factory()->create(['airport_id' => $a->id]);

        $this->actingAs($admin)->delete(route('airports.destroy', $a))->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('airports', ['id' => $a->id]);
    }

    public function test_an_unused_airport_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Airport::create(['code' => 'XXX', 'name' => 'Spare', 'is_active' => true]);

        $this->actingAs($admin)->delete(route('airports.destroy', $a))->assertRedirect();
        $this->assertDatabaseMissing('airports', ['id' => $a->id]);
    }

    public function test_a_driver_cannot_manage_airports(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('airports.index'))->assertForbidden();
        $this->actingAs($driver)->post(route('airports.store'), ['code' => 'ZZZ', 'name' => 'X'])->assertForbidden();
    }
}
