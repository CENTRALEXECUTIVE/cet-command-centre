<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 24 Nov 2026: a US customer arriving at Manchester (return booking) could never finish —
 * the form copied his landing time into the RETURN pickup, the server refused it, and the
 * widget reloaded EMPTY with no message. Server errors must always be shown, with the
 * customer's details kept, and the landing time must go to the leg collected at the airport.
 */
class WidgetBookingErrorsShownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_a_refused_booking_shows_why_and_keeps_the_details(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $at = now()->addDays(20)->format('Y-m-d\TH:i');

        $this->from(route('widget.book'))->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Manchester Airport (MAN), Manchester M90 1QX', 'pickup_postcode' => 'M90 1QX',
            'destination_address' => 'Sheffield S1 4QS', 'destination_postcode' => 'S1 4QS',
            'pickup_at' => $at, 'return_pickup_at' => $at,          // same moment: refused
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'customer_first_name' => 'Graham', 'customer_surname' => 'Bonner',
            'customer_phone' => '+1 901 338 4278', 'customer_email' => 'graham@example.com',
            'flight_number' => 'DL5986', 'flight_landing_at' => $at,
        ])->assertRedirect(route('widget.book'));

        $this->assertSame(0, \App\Models\Booking::count());
        $this->get(route('widget.book'))->assertOk()
            ->assertSee('We couldn’t complete your booking yet', false)
            ->assertSee('value="Bonner"', false)
            ->assertSee('value="+1 901 338 4278"', false)
            ->assertSee("document.querySelector('#b-journey-seg [data-journey=", false);
    }

    public function test_the_landing_time_only_fills_the_return_when_the_airport_is_the_drop_off(): void
    {
        $html = $this->get(route('widget.book'))->getContent();
        $this->assertStringContainsString('if (isAirportPickup() && whenEl && flightLandEl.value)', $html);
        $this->assertStringContainsString('else if (isAirportReturn() && isAirportDropoff() && retEl', $html);
    }

    public function test_a_us_arrival_return_books_when_the_return_is_later(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $out = now()->addDays(20)->setTime(10, 30);

        $this->post(route('widget.book.store'), [
            'journey_type' => 'return',
            'pickup_address' => 'Manchester Airport (MAN), Manchester M90 1QX', 'pickup_postcode' => 'M90 1QX',
            'destination_address' => 'Sheffield S1 4QS', 'destination_postcode' => 'S1 4QS',
            'pickup_at' => $out->format('Y-m-d\TH:i'), 'return_pickup_at' => $out->copy()->addDays(5)->setTime(18, 30)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'customer_first_name' => 'Graham', 'customer_surname' => 'Bonner',
            'customer_phone' => '+1 901 338 4278', 'customer_email' => 'graham@example.com',
            'flight_number' => 'DL5986', 'flight_landing_at' => $out->format('Y-m-d\TH:i'),
            'payment_method' => 'card', 'accept_terms' => 1, 'accept_privacy' => 1,
        ])->assertOk();

        $this->assertGreaterThanOrEqual(1, \App\Models\Booking::count());
    }
}
