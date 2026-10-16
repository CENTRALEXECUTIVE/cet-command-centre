<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The New Booking form has postcode fields wired to the postcode → address
 * lookup: type a postcode to list every address there and pick one, and picking
 * an address back-fills the postcode. (Behaviour lives in cet-forms.js via the
 * data-postcode-fill / data-postcode-target attributes.)
 */
class AdminPostcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_form_has_postcode_lookup_fields_wired(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('bookings.create'))
            ->assertOk()
            // Postcode fields that drive the address list…
            ->assertSee('data-postcode-fill="#pickup_address"', false)
            ->assertSee('data-postcode-fill="#destination_address"', false)
            // …and address fields that back-fill the postcode.
            ->assertSee('data-postcode-target="#pickup_postcode"', false)
            ->assertSee('data-postcode-target="#destination_postcode"', false)
            // The lookup endpoints are exposed to the page.
            ->assertSee('CET_ADDRESSES_URL', false)
            ->assertSee('CET_RESOLVE_URL', false);
    }
}
