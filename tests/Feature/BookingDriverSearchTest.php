<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors, 16 Nov 2026: find every job a driver has done, past and future. */
class BookingDriverSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_pick_a_driver_or_search_by_driver_name(): void
    {
        $admin = User::factory()->admin()->create();
        $jabir = User::factory()->create(['role' => 'driver', 'name' => 'Jabir Test']);
        $other = User::factory()->create(['role' => 'driver', 'name' => 'Other Driver']);
        $c = Customer::create(['name' => 'Cust A', 'phone' => '07700900991']);
        $past = Booking::factory()->create(['customer_id' => $c->id, 'driver_id' => $jabir->id, 'pickup_at' => now()->subDays(3), 'reference' => 'CET-PAST01']);
        $future = Booking::factory()->create(['customer_id' => $c->id, 'driver_id' => $jabir->id, 'pickup_at' => now()->addDays(3), 'reference' => 'CET-FUTR01']);
        Booking::factory()->create(['customer_id' => $c->id, 'driver_id' => $other->id, 'pickup_at' => now()->addDays(2), 'reference' => 'CET-OTHR01']);

        $this->actingAs($admin)->get(route('bookings.index'))->assertOk()->assertSee('All drivers')->assertSee('Jabir Test');

        $this->actingAs($admin)->get(route('bookings.index', ['driver' => 'Jabir Test']))->assertOk()
            ->assertSee('CET-PAST01')->assertSee('CET-FUTR01')->assertDontSee('CET-OTHR01');

        $this->actingAs($admin)->get(route('bookings.index', ['q' => 'jabir']))->assertOk()
            ->assertSee('CET-PAST01')->assertSee('CET-FUTR01')->assertDontSee('CET-OTHR01');
    }
}
