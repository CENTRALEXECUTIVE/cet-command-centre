<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Where website bookings come from (26 Nov 2026). */
class BookingSourceTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Src', 'customer_surname' => 'Tester',
            'customer_phone' => '07700900888', 'customer_email' => 'src@example.com',
        ], $o);
    }

    public function test_the_page_booked_from_and_social_click_ids_are_recorded(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        // The form is opened inside our website's Manchester page, from a Facebook link.
        $this->withHeader('Referer', 'https://www.centralexecutivetransfers.co.uk/manchester-airport-transfers?x=1')
            ->get(route('widget.book', ['fbclid' => 'IwAR123']))
            ->assertOk()->assertSee('name="ref_page" value="/manchester-airport-transfers"', false)
            ->assertSee('name="fbclid" value="IwAR123"', false);

        $this->post(route('widget.book.store'), $this->base(['fbclid' => 'IwAR123', 'ref_page' => '/manchester-airport-transfers']))->assertOk();
        $b = Booking::latest('id')->first();
        $this->assertSame('meta', $b->heardAboutKey());
        $this->assertSame('/manchester-airport-transfers', $b->bookedFromPage());
        $this->assertSame('Facebook / Instagram (link)', Booking::heardAboutLabel($b->heardAboutKey()));
    }

    public function test_an_untagged_website_booking_is_labelled_website_and_tiktok_is_recognised(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $this->post(route('widget.book.store'), $this->base())->assertOk();
        $this->assertSame('website', Booking::latest('id')->first()->heardAboutKey());

        $this->post(route('widget.book.store'), $this->base(['ttclid' => 'tt1', 'customer_email' => 'tt@example.com', 'customer_phone' => '07700900889']))->assertOk();
        $this->assertSame('tiktok', Booking::latest('id')->first()->heardAboutKey());

        // Another site's page is never recorded as "booked from".
        $this->withHeader('Referer', 'https://evil.example.com/page')->get(route('widget.book'))
            ->assertOk()->assertDontSee('name="ref_page"', false);
    }
}
