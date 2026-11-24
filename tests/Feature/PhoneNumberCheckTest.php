<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use App\Support\Phone;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directors (24 Nov 2026): a website booking came in with 0789456123 (a digit
 * short). Customers must be stopped to recheck; the office may still save.
 */
class PhoneNumberCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_checker_spots_short_long_and_fake_numbers(): void
    {
        $this->assertNotNull(Phone::problem('0789456123'));
        $this->assertNotNull(Phone::problem('077532176431'));
        $this->assertNotNull(Phone::problem('07777777777'));
        $this->assertNotNull(Phone::problem('+44 789456123'));
        $this->assertNull(Phone::problem('07753 217643'));
        $this->assertNull(Phone::problem('+44 (0)7753 217643'));
        $this->assertNull(Phone::problem('0114 399 8242'));
        $this->assertNull(Phone::problem('+33664200875'));
        $this->assertNull(Phone::problem('0049 151 204 22619'));
    }

    public function test_the_website_refuses_a_number_with_a_digit_missing(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $executive = VehicleType::where('slug', 'executive')->first();
        $payload = [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_first_name' => 'Jo', 'customer_surname' => 'Smith', 'customer_phone' => '0789456123',
        ];

        $this->post(route('widget.book.store'), $payload)->assertSessionHasErrors(['customer_phone' => 'This mobile number looks too short. UK mobiles have 11 digits, for example 07700 900123. Please check it.']);
        $this->assertSame(0, Booking::count());

        $this->post(route('widget.book.store'), ['customer_phone' => '07894561234'] + $payload)->assertOk();
        $this->assertSame(1, Booking::count());
    }

    public function test_the_website_needs_a_surname(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $executive = VehicleType::where('slug', 'executive')->first();
        $payload = [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1, 'customer_phone' => '07700900123',
        ];
        $this->post(route('widget.book.store'), $payload + ['customer_first_name' => 'Jake', 'customer_surname' => ''])
            ->assertSessionHasErrors('customer_surname');
        $this->post(route('widget.book.store'), $payload + ['customer_name' => 'Jake'])
            ->assertSessionHasErrors(['customer_name' => 'Please enter your first name and surname.']);
        $this->assertSame(0, Booking::count());

        $this->post(route('widget.book.store'), $payload + ['customer_first_name' => 'Jake', 'customer_surname' => 'Callum'])->assertOk();
        $this->assertSame('Jake Callum', Booking::first()->customer->name);
    }
}
