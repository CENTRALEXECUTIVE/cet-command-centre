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

    public function test_an_edit_that_saved_the_bookers_name_still_shows_the_passenger(): void
    {
        $c = Customer::factory()->create(['name' => 'Claire  Green']);
        $b = Booking::factory()->create(['customer_id' => $c->id, 'meta' => [
            'lead_name' => 'Matthias Perez y Schmidt', 'booked_by' => 'Claire Green', 'name_override' => 'Claire  Green']]);

        $this->assertSame('Matthias Perez y Schmidt', $b->fresh()->displayName());
        $this->assertSame('Matthias Perez y Schmidt', $b->fresh()->displayCustomerName());
    }

    public function test_passenger_comes_from_the_calendar_when_lead_name_was_overwritten_with_the_booker(): void
    {
        $c = Customer::factory()->create(['name' => 'Claire  Green']);
        $b = Booking::factory()->create(['customer_id' => $c->id, 'meta' => ['lead_name' => 'Claire Green', 'booked_by' => 'Claire Green']]);
        \App\Models\CalendarEvent::create([
            'booking_id' => $b->id, 'calendar_id' => 'cal', 'google_event_id' => 'evt_b3411f', 'title' => '*Matthias LHR (ABDI)*',
            'description' => "📑 *Booking Confirmation*\n• *Customer Name:* Matthias Perez y Schmidt\n• *Notes:* Booked by Claire Green",
            'start_at' => $b->pickup_at, 'end_at' => $b->pickup_at->copy()->addHour(), 'sync_status' => 'synced',
        ]);

        $this->assertSame('Matthias Perez y Schmidt', $b->fresh(['calendarEvent'])->displayName());
    }

    public function test_a_normal_booking_name_is_unchanged(): void
    {
        $c = Customer::factory()->create(['name' => 'Jane Smith']);
        $b = Booking::factory()->create(['customer_id' => $c->id, 'meta' => ['name_override' => 'Jane Smith-Jones']]);
        $this->assertSame('Jane Smith-Jones', $b->fresh()->displayName());
    }

    public function test_every_common_number_format_is_blanked_for_the_driver_but_the_note_stays(): void
    {
        foreach (['Call her on +44 (0) 7700 900999 when outside', 'ring (0114) 234 5678 on arrival', 'text 07700.900.999', 'mob: 7700 900999'] as $note) {
            $b = Booking::factory()->create(['special_requests' => $note]);
            $safe = $b->driverSafeText($note);
            $this->assertStringContainsString('(number via the office)', $safe, $note);
            $this->assertDoesNotMatchRegularExpression('/\d{3}[\s.]?\d{3}/', $safe, $note);
        }
        $b = Booking::factory()->create(['special_requests' => 'Flight BA1234 lands 10:30, £120.00 cash, ref CET-7692CC']);
        $this->assertSame([], $b->notePhoneNumbers());
    }
}
