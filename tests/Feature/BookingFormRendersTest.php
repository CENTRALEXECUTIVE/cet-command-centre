<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AirportSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFormRendersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, AirportSeeder::class]);
    }

    public function test_booking_form_renders_with_autocomplete_and_quote(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.create'))
            ->assertOk()
            ->assertSee('data-places', false)          // address autocomplete hooks
            ->assertSee('js/cet-forms.js', false)       // shared helper loaded
            ->assertSee('id="quote-note"', false)       // live quote display
            ->assertSee(route('pricing.estimate'), false);
    }

    public function test_quote_form_renders_with_autocomplete(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('quotes.create'))
            ->assertOk()
            ->assertSee('data-places', false)
            ->assertSee('js/cet-forms.js', false)
            ->assertSee('id="quote-note"', false);
    }
}
