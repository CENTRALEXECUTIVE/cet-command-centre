<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** 27 Nov 2026: a return has its own page, and can be from / to a different address. */
class ReturnDifferentAddressTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN), Manchester M90 1QX', 'destination_postcode' => 'M90 1QX',
            'pickup_at' => now()->addDays(10)->setTime(6, 0)->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(14)->setTime(18, 0)->format('Y-m-d\TH:i'),
            'flight_number' => 'BA1234', 'flight_landing_at' => now()->addDays(14)->setTime(18, 0)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Ret', 'customer_surname' => 'Tester', 'customer_phone' => '07700900444', 'customer_email' => 'ret@example.com',
            'payment_method' => 'card',
        ], $o);
    }

    public function test_the_return_leg_uses_the_addresses_from_the_return_page(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->base([
            'return_pickup_address' => 'Leeds Bradford Airport (LBA), Leeds LS19 7TU',
            'return_destination_address' => 'Rotherham S60 5WG',
        ]))->assertOk()->assertSessionHasNoErrors();

        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertStringContainsString('Leeds Bradford', $ret->pickup_address);
        $this->assertStringContainsString('Rotherham', $ret->destination_address);
        $this->assertSame('BA1234', $ret->flight_number, 'the flight we meet goes on the airport collection leg');
        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $this->assertStringContainsString('Manchester Airport', $out->destination_address);
    }

    public function test_left_blank_the_return_is_the_outbound_reversed(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->base())->assertOk()->assertSessionHasNoErrors();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertStringContainsString('Manchester Airport', $ret->pickup_address);
        $this->assertStringContainsString('Sheffield', $ret->destination_address);
    }

    public function test_prices_use_the_return_route(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $common = ['pickup' => 'Sheffield S1 2HH', 'destination' => 'Manchester Airport (MAN), Manchester M90 1QX',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'), 'journey_type' => 'return',
            'return_pickup_at' => now()->addDays(14)->format('Y-m-d\TH:i')];
        $exec = VehicleType::where('slug', 'executive')->value('id');
        $same = collect($this->postJson(route('widget.prices'), $common)->json('options'))->firstWhere('id', $exec);
        $diff = collect($this->postJson(route('widget.prices'), $common + ['return_pickup' => 'Heathrow Airport (LHR), London TW6 1QG'])->json('options'))->firstWhere('id', $exec);
        $this->assertNotEquals($same['total'] ?? null, $diff['total'] ?? null, 'a different return pickup changes the return price');
    }

    public function test_the_form_has_its_own_return_page(): void
    {
        $html = $this->get(route('widget.book'))->assertOk()->getContent();
        $this->assertStringContainsString('data-step="4"', $html);
        $this->assertStringContainsString('Your return journey', $html);
        $this->assertStringContainsString('name="return_pickup_address"', $html);
        $this->assertStringContainsString('name="return_destination_address"', $html);
    }

    public function test_the_return_keeps_its_own_passengers_and_luggage(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->base([
            'passengers' => 1, 'suitcases' => 0, 'hand_luggage' => 1,
            'return_passengers' => 2, 'return_suitcases' => 2, 'return_hand_luggage' => 0,
        ]))->assertOk()->assertSessionHasNoErrors();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $this->assertSame(2, (int) $ret->passengers);
        $this->assertSame(2, (int) $ret->meta['suitcases']);
        $this->assertSame(1, (int) $out->passengers);

        // Not given → the return copies the outbound.
        $this->post(route('widget.book.store'), $this->base(['passengers' => 3, 'suitcases' => 1, 'customer_email' => 'ret2@example.com']));
        $ret2 = Booking::where('is_return_leg', true)->latest('id')->first();
        $this->assertSame(3, (int) $ret2->passengers);
    }

    private function trip(array $o): array
    {
        return array_merge([
            'journey_type' => 'return',
            'pickup_at' => now()->addDays(10)->setTime(6, 0)->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(14)->setTime(18, 0)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Fly', 'customer_surname' => 'Tester', 'customer_phone' => '07700900555', 'customer_email' => uniqid().'@example.com',
            'payment_method' => 'card',
        ], $o);
    }

    // Directors, 27 Nov 2026: the flight goes on whichever leg collects at the airport.
    public function test_return_collecting_at_the_airport_carries_the_return_flight(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $land = now()->addDays(14)->setTime(19, 15)->format('Y-m-d\TH:i');
        $this->post(route('widget.book.store'), $this->trip([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN), Manchester M90 1QX',
            'return_flight_number' => 'ba1369', 'return_flight_landing_at' => $land,
        ]))->assertOk()->assertSessionHasNoErrors();
        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertNull($out->flight_number);
        $this->assertSame('BA1369', $ret->flight_number);
        $this->assertSame($land, $ret->pickup_at->format('Y-m-d\TH:i'), 'the return pickup is the landing time');
    }

    public function test_outbound_collecting_at_the_airport_carries_the_outbound_flight_on_page_one(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->trip([
            'pickup_address' => 'Manchester Airport (MAN), Manchester M90 1QX', 'pickup_postcode' => 'M90 1QX',
            'destination_address' => 'Sheffield S1 2HH', 'destination_postcode' => 'S1 2HH',
            'flight_number' => 'LH940', 'flight_landing_at' => now()->addDays(10)->setTime(11, 10)->format('Y-m-d\TH:i'),
        ]))->assertOk()->assertSessionHasNoErrors();
        $this->assertSame('LH940', Booking::where('is_return_leg', false)->first()->flight_number);
        $this->assertNull(Booking::where('is_return_leg', true)->first()->flight_number);
    }

    public function test_both_legs_at_airports_each_get_their_own_flight(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $base = [
            'pickup_address' => 'Manchester Airport (MAN), Manchester M90 1QX', 'pickup_postcode' => 'M90 1QX',
            'destination_address' => 'Sheffield S1 2HH', 'destination_postcode' => 'S1 2HH',
            'return_pickup_address' => 'Leeds Bradford Airport (LBA), Leeds LS19 7TU', 'return_destination_address' => 'Sheffield S1 2HH',
            'flight_number' => 'LH940', 'flight_landing_at' => now()->addDays(10)->setTime(11, 10)->format('Y-m-d\TH:i'),
        ];
        // Missing the return flight → asked for, on the Return page.
        $this->post(route('widget.book.store'), $this->trip($base))->assertSessionHasErrors('return_flight_number');
        $this->post(route('widget.book.store'), $this->trip($base + [
            'return_flight_number' => 'EZY707', 'return_flight_landing_at' => now()->addDays(14)->setTime(20, 0)->format('Y-m-d\TH:i'),
        ]))->assertOk()->assertSessionHasNoErrors();
        $this->assertSame('LH940', Booking::where('is_return_leg', false)->latest('id')->first()->flight_number);
        $this->assertSame('EZY707', Booking::where('is_return_leg', true)->latest('id')->first()->flight_number);
    }

    public function test_an_old_page_sending_one_flight_still_puts_it_on_the_right_leg(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->trip([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN), Manchester M90 1QX',
            'flight_number' => 'BA1369', 'flight_landing_at' => now()->addDays(14)->setTime(19, 15)->format('Y-m-d\TH:i'),
        ]))->assertOk()->assertSessionHasNoErrors();
        $this->assertSame('BA1369', Booking::where('is_return_leg', true)->first()->flight_number);
    }

    public function test_the_flight_boxes_sit_on_the_journey_and_return_pages(): void
    {
        $html = $this->get(route('widget.book'))->assertOk()->getContent();
        $step1 = substr($html, strpos($html, 'data-step="1"'), strpos($html, 'data-step="4"') - strpos($html, 'data-step="1"'));
        $step4 = substr($html, strpos($html, 'data-step="4"'), strpos($html, 'data-step="2"') - strpos($html, 'data-step="4"'));
        $this->assertStringContainsString('name="flight_number"', $step1);
        $this->assertStringContainsString('name="return_flight_number"', $step4);
    }
}
