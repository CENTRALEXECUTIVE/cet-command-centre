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

    // Directors, 27 Nov 2026: a phone number never blocks a booking — it's saved and flagged.
    public function test_a_number_with_a_digit_missing_is_saved_and_flagged_not_refused(): void
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

        $this->post(route('widget.book.store'), $payload)->assertOk()->assertSessionHasNoErrors();
        $this->assertSame(1, Booking::count());
        $this->assertStringContainsString('Phone number looked wrong', implode(' ', Booking::first()->meta['passed_through'] ?? []));

        $this->post(route('widget.book.store'), ['customer_phone' => '07894561234', 'pickup_at' => now()->addDays(2)->format('Y-m-d\\TH:i')] + $payload)->assertOk();
        $this->assertSame(2, Booking::count());
        $this->assertEmpty(Booking::latest('id')->first()->meta['passed_through'] ?? null, 'a good number is not flagged');
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
