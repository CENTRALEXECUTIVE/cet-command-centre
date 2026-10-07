<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cet:check-customer-links finds (and can fix) bookings stapled to the wrong
 * shared customer record — the Huzayfa-under-Neil class of bug.
 */
class CheckCustomerLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_a_booking_filed_under_a_shared_wrong_record(): void
    {
        $neil = Customer::factory()->create(['name' => 'Neil Simmonds', 'phone' => '+447535823380']);
        // Neil's own booking (makes his record "shared").
        Booking::factory()->create(['customer_id' => $neil->id]);
        // Huzayfa's booking wrongly filed under Neil, with its own number in notes.
        $huz = Booking::factory()->create([
            'customer_id' => $neil->id,
            'meta' => ['lead_name' => 'Huzayfa', 'contact_override' => '+447379921855'],
        ]);

        $this->artisan('cet:check-customer-links')
            ->expectsOutputToContain('Huzayfa')
            ->assertSuccessful();

        // Report-only: nothing moved.
        $this->assertSame($neil->id, $huz->fresh()->customer_id);
    }

    public function test_fix_refiles_the_booking_and_leaves_the_shared_record_untouched(): void
    {
        $neil = Customer::factory()->create(['name' => 'Neil Simmonds', 'phone' => '+447535823380']);
        $neilBooking = Booking::factory()->create(['customer_id' => $neil->id]);
        $huz = Booking::factory()->create([
            'customer_id' => $neil->id,
            'meta' => ['lead_name' => 'Huzayfa', 'contact_override' => '+447379921855'],
        ]);

        $this->artisan('cet:check-customer-links --fix')->assertSuccessful();

        // Neil untouched, still on his own booking.
        $neil->refresh();
        $this->assertSame('Neil Simmonds', $neil->name);
        $this->assertSame('+447535823380', $neil->phone);
        $this->assertSame($neil->id, $neilBooking->fresh()->customer_id);

        // Huzayfa's booking moved to a new customer named from the booking.
        $huz = $huz->fresh('customer');
        $this->assertNotSame($neil->id, $huz->customer_id);
        $this->assertSame('Huzayfa', $huz->customer->name);
    }

    public function test_a_solo_booker_vs_passenger_booking_is_not_flagged(): void
    {
        // A company/booker record used by only ONE booking, with a different lead
        // passenger, is legitimate — not a wrong link.
        $booker = Customer::factory()->create(['name' => 'Gen2 Construction Ltd']);
        Booking::factory()->create([
            'customer_id' => $booker->id,
            'meta' => ['lead_name' => 'A Passenger'],
        ]);

        $this->artisan('cet:check-customer-links')
            ->expectsOutputToContain('No wrongly-linked bookings found')
            ->assertSuccessful();
    }
}
