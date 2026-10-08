<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "bill more than one booking on this invoice" picker searches for a booking
 * by reference, passenger name, operator or address — so the office can match
 * jobs up without typing an exact reference.
 */
class InvoiceCombineSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_it_finds_a_booking_by_passenger_name(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-BASE']);
        $emma = Customer::factory()->create(['name' => 'Emma Glaves']);
        $target = Booking::factory()->create(['reference' => 'CET-TGT', 'customer_id' => $emma->id]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.invoice.search', $base).'?q=glaves');

        $res->assertOk();
        $this->assertContains('CET-TGT', collect($res->json('results'))->pluck('reference')->all());
    }

    public function test_it_finds_a_booking_by_reference(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-BASE']);
        Booking::factory()->create(['reference' => 'FLCCGSB']);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.invoice.search', $base).'?q=flccg');

        $this->assertContains('FLCCGSB', collect($res->json('results'))->pluck('reference')->all());
    }

    public function test_it_finds_a_booking_by_operator_name(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-BASE']);
        $cover = Booking::factory()->create(['reference' => 'CET-COVER']);
        $cover->forceFill(['meta' => ['cover_for' => ['name' => 'A1 Cars Ltd', 'amount' => 90]]])->save();

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.invoice.search', $base).'?q=a1 cars');

        $refs = collect($res->json('results'))->pluck('reference')->all();
        $this->assertContains('CET-COVER', $refs);
    }

    public function test_it_excludes_this_booking_and_already_combined_ones(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-BASE']);
        $already = Booking::factory()->create(['reference' => 'CET-BASE2']);
        $base->addToInvoiceGroup($already);

        // Searching "CET-BASE" must offer neither this booking nor the one already on it.
        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.invoice.search', $base).'?q=cet-base');

        $refs = collect($res->json('results'))->pluck('reference')->all();
        $this->assertNotContains('CET-BASE', $refs);
        $this->assertNotContains('CET-BASE2', $refs);
    }

    public function test_empty_query_lists_recent_bookings_to_browse(): void
    {
        // With no (or too-short) query the picker lists recent bookings so the
        // operator can tap one without typing.
        $base = Booking::factory()->create(['reference' => 'CET-BASE']);
        Booking::factory()->create(['reference' => 'CET-RECENT', 'pickup_at' => now()->addDay()]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('bookings.invoice.search', $base));

        $res->assertOk();
        $refs = collect($res->json('results'))->pluck('reference')->all();
        $this->assertContains('CET-RECENT', $refs);
        $this->assertNotContains('CET-BASE', $refs); // never offers this booking itself
    }

    public function test_non_admins_are_forbidden(): void
    {
        $base = Booking::factory()->create(['reference' => 'CET-BASE']);
        $driver = User::factory()->create();

        $this->actingAs($driver)
            ->getJson(route('bookings.invoice.search', $base).'?q=test')
            ->assertForbidden();
    }
}
