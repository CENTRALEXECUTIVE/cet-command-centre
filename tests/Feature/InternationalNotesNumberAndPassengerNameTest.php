<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CET-B3411F, 23 Nov 2026: the passenger's German mobile in the notes
 * ("0049 151 204 22619") was offered as "0049 151 204 22" (cut off), and the
 * driver's job showed the booker (Claire Green) instead of the passenger.
 */
class InternationalNotesNumberAndPassengerNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_international_number_in_the_notes_is_read_in_full(): void
    {
        $b = Booking::factory()->create(['special_requests' => 'Booked by Claire. Passenger: Matthias (0049 151 204 22619). Alt +33664200875']);
        $nums = $b->notePhoneNumbers();
        $this->assertContains('0049 151 204 22619', $nums);
        $this->assertContains('+33664200875', $nums);
        $this->assertSame('+4915120422619', \App\Support\Phone::e164('0049 151 204 22619'));
    }

    public function test_uk_numbers_in_notes_still_work(): void
    {
        $b = Booking::factory()->create(['special_requests' => 'Ring 07700 900123 or +44 7405 172435 on landing']);
        $this->assertSame(['07700 900123', '+44 7405 172435'], $b->notePhoneNumbers());
    }

    public function test_the_passenger_not_the_booker_is_the_name_on_the_job(): void
    {
        $c = Customer::factory()->create(['name' => 'Claire Green']);
        $b = Booking::factory()->create(['customer_id' => $c->id,
            'meta' => ['lead_name' => 'Matthias Perez y Schmidt', 'booked_by' => 'Claire Green', 'edited_fields' => ['customer_name']]]);

        $this->assertSame('Matthias Perez y Schmidt', $b->fresh()->displayName());
        $this->assertSame('Matthias Perez y Schmidt', $b->fresh()->displayCustomerName());
    }
}
