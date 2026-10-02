<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two real bugs seen on live jobs:
 *  - "Arrival (Meet & Greet) (Meet & Greet)" — the suffix was doubled.
 *  - "FREE ROAM" on an airport job ("Terminal 2, Manchester") — airport missed.
 */
class CalendarTitleFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(array $meta, array $attrs = []): Booking
    {
        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create(array_merge([
            'customer_id' => Customer::create(['name' => 'Test Lead'])->id,
            'airport_id' => null,
            'meta' => $meta,
        ], $attrs));
    }

    public function test_meet_and_greet_is_not_doubled_in_the_heading(): void
    {
        $b = $this->booking([
            'journey_label' => 'Arrival (Meet & Greet)', // already includes it (legacy import)
            'meet_and_greet' => true,
        ]);

        $desc = app(CalendarEventBuilder::class)->preview($b)['description'];

        $this->assertStringContainsString('Arrival (Meet & Greet)', $desc);
        $this->assertStringNotContainsString('(Meet & Greet) (Meet & Greet)', $desc);
    }

    public function test_meet_and_greet_is_appended_once_from_a_plain_label(): void
    {
        $b = $this->booking(['journey_label' => 'Arrival', 'meet_and_greet' => true]);
        $desc = app(CalendarEventBuilder::class)->preview($b)['description'];
        $this->assertStringContainsString('Booking Confirmation – Arrival (Meet & Greet)', $desc);
    }

    public function test_a_terminal_city_pickup_titles_as_the_airport_not_free_roam(): void
    {
        $b = $this->booking(
            ['where' => 'FREE ROAM', 'lead_name' => 'Janine Neill'], // stale/wrong stored where
            ['pickup_address' => 'Terminal 2, Manchester', 'destination_address' => '2 Worrygoose Lane, Rotherham']
        );

        $title = app(CalendarEventBuilder::class)->preview($b)['title'];

        $this->assertStringContainsString('MAN', $title);
        $this->assertStringNotContainsString('FREE ROAM', $title);
    }
}
