<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_admin_can_search_customers(): void
    {
        $admin = User::factory()->admin()->create();
        Customer::create(['name' => 'Rebecca Lord', 'phone' => '07700900001']);
        Customer::create(['name' => 'James Watson', 'phone' => '07700900002']);

        $this->actingAs($admin)->get(route('customers.index', ['q' => 'Rebecca']))
            ->assertOk()
            ->assertSee('Rebecca Lord')
            ->assertDontSee('James Watson');
    }

    public function test_profile_shows_history_and_lifetime_value(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Big Spender', 'phone' => '07700900003']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subDays(3),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'complete', 'payment_method' => 'card', 'final_price' => 120,
        ]);
        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subDay(),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'cancelled', 'payment_method' => 'card', 'quoted_price' => 80,
        ]);

        $res = $this->actingAs($admin)->get(route('customers.show', $customer))->assertOk();
        // Cancelled booking is excluded from lifetime value (£120, not £200).
        $this->assertEquals(120.0, $res->viewData('lifetimeValue'));
        $res->assertSee('Big Spender');
    }

    public function test_admin_can_flag_vip_and_add_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'VIP Person', 'phone' => '07700900004']);

        $this->actingAs($admin)->put(route('customers.update', $customer), [
            'name' => 'VIP Person', 'phone' => '07700900004',
            'is_vip' => 1, 'marketing_consent' => 1, 'notes' => 'Prefers the V-Class.',
        ])->assertRedirect();

        $customer->refresh();
        $this->assertTrue($customer->is_vip);
        $this->assertTrue($customer->marketing_consent);
        $this->assertNotNull($customer->marketing_consent_at);
        $this->assertEquals('Prefers the V-Class.', $customer->notes);
    }

    public function test_admin_can_save_and_remove_addresses(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'Addr Person', 'phone' => '07700900005']);

        $this->actingAs($admin)->post(route('customers.addresses.store', $customer), [
            'label' => 'Home', 'address' => '12 Fargate, Sheffield', 'postcode' => 'S1 2GA',
        ])->assertRedirect();

        $address = $customer->addresses()->first();
        $this->assertEquals('Home', $address->label);

        $this->actingAs($admin)->delete(route('customers.addresses.destroy', [$customer, $address]))->assertRedirect();
        $this->assertEquals(0, $customer->addresses()->count());
    }

    public function test_new_booking_prefills_from_a_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'Rebook Me', 'phone' => '07700900006', 'email' => 'rebook@x.com']);

        $this->actingAs($admin)->get(route('bookings.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Rebook Me')
            ->assertSee('07700900006');
    }

    public function test_admin_can_remove_a_junk_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'ABDI TEST', 'phone' => '07700900008']);

        $this->actingAs($admin)->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        // Soft-deleted: hidden from the directory but not gone.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->actingAs($admin)->get(route('customers.index'))->assertDontSee('ABDI TEST');
    }

    public function test_driver_cannot_access_customers(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $customer = Customer::create(['name' => 'X', 'phone' => '07700900007']);
        $this->actingAs($driver)->get(route('customers.index'))->assertForbidden();
        $this->actingAs($driver)->get(route('customers.show', $customer))->assertForbidden();
    }
}
