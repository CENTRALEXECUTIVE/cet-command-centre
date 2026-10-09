<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "match as return pair" picker browses/searches existing bookings to pair
 * with — the same customer's jobs first, excluding this one and anything already
 * paired or already a return leg.
 */
class MatchReturnSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_it_lists_candidates_and_excludes_this_booking(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-OUT']);
        Booking::factory()->create(['reference' => 'CET-OTHER', 'pickup_at' => now()->addDay()]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.match-return.search', $base));

        $res->assertOk();
        $refs = collect($res->json('results'))->pluck('reference')->all();
        $this->assertContains('CET-OTHER', $refs);
        $this->assertNotContains('CET-OUT', $refs);
    }

    public function test_it_excludes_already_paired_and_return_legs(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-OUT']);
        $someLeg = Booking::factory()->create(['reference' => 'CET-LEG']);
        Booking::factory()->create(['reference' => 'CET-PAIRED', 'linked_booking_id' => $someLeg->id]);
        Booking::factory()->create(['reference' => 'CET-RETURN', 'is_return_leg' => true]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.match-return.search', $base));

        $refs = collect($res->json('results'))->pluck('reference')->all();
        $this->assertNotContains('CET-PAIRED', $refs);
        $this->assertNotContains('CET-RETURN', $refs);
    }

    public function test_same_customer_is_surfaced_first(): void
    {
        $emma = Customer::factory()->create(['name' => 'Emma Glaves']);
        $base = Booking::factory()->create(['reference' => 'CET-OUT', 'customer_id' => $emma->id]);
        // A newer booking for a DIFFERENT customer, and an older one for the SAME.
        Booking::factory()->create(['reference' => 'CET-OTHER', 'pickup_at' => now()->addDays(5)]);
        Booking::factory()->create(['reference' => 'CET-EMMA-RET', 'customer_id' => $emma->id, 'pickup_at' => now()->addDay()]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.match-return.search', $base));

        $refs = collect($res->json('results'))->pluck('reference')->all();
        $this->assertSame('CET-EMMA-RET', $refs[0]); // same customer first, despite older date
    }

    public function test_it_searches_by_name(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-OUT']);
        $jim = Customer::factory()->create(['name' => 'Jim Beckett']);
        Booking::factory()->create(['reference' => 'CET-JIM', 'customer_id' => $jim->id]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.match-return.search', $base).'?q=beckett');

        $this->assertContains('CET-JIM', collect($res->json('results'))->pluck('reference')->all());
    }

    public function test_non_admins_are_forbidden(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-OUT']);
        $this->actingAs(User::factory()->create())
            ->getJson(route('bookings.match-return.search', $base).'?q=test')
            ->assertForbidden();
    }
}
