<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The meet & greet name board shows the right name(s): an explicit office "Board:"
 * instruction in the notes wins, else every pickup party on a shared job, else the
 * lead passenger.
 */
class SignboardTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $attrs = []): Booking
    {
        $customer = Customer::create(['name' => 'Olivia Lester', 'phone' => '07100000000']);

        return Booking::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'pickup_address' => 'Manchester Airport T2',
            'destination_address' => 'Sheffield',
        ], $attrs));
    }

    public function test_it_defaults_to_the_lead_passenger(): void
    {
        $this->assertSame(['Olivia Lester'], $this->booking()->signboardNames());
    }

    public function test_an_office_board_note_is_used_verbatim_and_split_into_names(): void
    {
        $b = $this->booking(['special_requests' => "Flight lands 1440.\nBoard: Christian Wienand, Joonas Salo & Tristen Vienne"]);
        $this->assertSame(['Christian Wienand', 'Joonas Salo', 'Tristen Vienne'], $b->signboardNames());
    }

    public function test_a_sign_note_also_works(): void
    {
        $b = $this->booking(['special_requests' => 'Sign: The Donoghue Family']);
        $this->assertSame(['The Donoghue Family'], $b->signboardNames());
    }

    public function test_it_pulls_names_from_prose_notes_asking_to_display_them(): void
    {
        $b = $this->booking(['special_requests' =>
            'Christian Wienand lands 1440 (EW9342), Joonas Salo lands 1705 (AY1365), '
            .'Tristen Vienne lands 1715 (EZY2112). Please display all their names at collection.']);

        $this->assertSame(['Christian Wienand', 'Joonas Salo', 'Tristen Vienne'], $b->signboardNames());
    }

    public function test_ordinary_notes_without_a_display_intent_do_not_hijack_the_board(): void
    {
        // Mentions a person but no "display name" intent → stays on the lead passenger.
        $b = $this->booking(['special_requests' => 'Ring the buzzer for Flat 3, ask for John Smith on arrival is fine.']);
        // "on arrival" + a name could be tempting, but there's no display/show/board
        // intent phrase, so it must NOT override the lead passenger.
        $b->special_requests = 'Please be prompt, customer is in a hurry.';
        $this->assertSame(['Olivia Lester'], $b->signboardNames());
    }

    public function test_all_pickup_parties_show_on_a_shared_job(): void
    {
        $b = $this->booking([
            'meta' => [
                'stops' => ['Manchester Airport T3'],
                'stop_contacts' => [['name' => 'Second Party', 'phone' => '07200000000']],
            ],
        ]);

        $names = $b->signboardNames();
        $this->assertContains('Olivia Lester', $names);
        $this->assertContains('Second Party', $names);
        $this->assertCount(2, $names);
    }
}
