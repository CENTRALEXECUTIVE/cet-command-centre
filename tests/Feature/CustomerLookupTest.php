<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The New Booking form's existing-customer lookup: find returning clients by
 * name, phone or email so the office can prefill instead of retyping.
 */
class CustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_a_customer_by_name(): void
    {
        Customer::factory()->create(['name' => 'Emma Glaves', 'phone' => '07700900111', 'email' => 'emma@example.com']);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('customers.search').'?q=glaves');

        $res->assertOk();
        $this->assertSame('Emma Glaves', $res->json('results.0.name'));
        $this->assertSame('07700900111', $res->json('results.0.phone'));
    }

    public function test_it_finds_by_phone_and_email(): void
    {
        Customer::factory()->create(['name' => 'Jim Beckett', 'phone' => '07711222333', 'email' => 'jim@beckett.co']);
        $admin = User::factory()->admin()->create();

        $this->assertSame('Jim Beckett', $this->actingAs($admin)->getJson(route('customers.search').'?q=222333')->json('results.0.name'));
        $this->assertSame('Jim Beckett', $this->actingAs($admin)->getJson(route('customers.search').'?q=beckett.co')->json('results.0.name'));
    }

    public function test_short_queries_return_nothing(): void
    {
        Customer::factory()->create(['name' => 'Al']);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('customers.search').'?q=a')
            ->assertOk()->assertJson(['results' => []]);
    }

    public function test_non_admins_are_forbidden(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->getJson(route('customers.search').'?q=emma')
            ->assertForbidden();
    }
}
