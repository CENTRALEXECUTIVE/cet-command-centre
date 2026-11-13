<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Office-only customer facts (online account etc.) on the customer and booking pages. */
class CustomerAdminFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_whether_the_customer_has_an_online_account(): void
    {
        $admin = User::factory()->admin()->create();
        $without = Customer::create(['name' => 'No Account', 'phone' => '07700900801']);
        $with = Customer::create(['name' => 'Has Account', 'phone' => '07700900802', 'email' => 'has@example.com']);
        $with->setLoginPassword('secret-pass-123');

        $this->actingAs($admin)->get(route('customers.show', $without))->assertOk()
            ->assertSee('Online account:')->assertSee('Email:')->assertSee('Missing');
        $this->actingAs($admin)->get(route('customers.show', $with))->assertOk()
            ->assertSee('Yes — can sign in');

        $booking = Booking::factory()->create(['customer_id' => $with->id, 'pickup_at' => now()->addDay()]);
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Yes — can sign in');
    }
}
