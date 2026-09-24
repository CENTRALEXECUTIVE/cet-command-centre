<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The customer booking form's address boxes use Google autocomplete via a PUBLIC
 * server proxy (no login), so the Google key never reaches the browser.
 */
class PublicAddressAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_places_endpoint_is_reachable_without_login(): void
    {
        // No key configured → graceful empty list, still a clean 200 JSON (no auth redirect).
        $this->getJson(route('public.book.places', ['q' => 'Sheffield']))
            ->assertOk()
            ->assertJson(['suggestions' => []]);
    }

    public function test_it_returns_google_suggestions_when_a_key_is_set(): void
    {
        Setting::set('google_maps_key', 'test-key', 'string', 'integrations');
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'suggestions' => [
                    ['placePrediction' => ['text' => ['text' => 'Sheffield Station, Sheaf St, Sheffield S1']]],
                ],
            ]),
        ]);

        $this->getJson(route('public.book.places', ['q' => 'Sheffield Station']))
            ->assertOk()
            ->assertJsonFragment(['suggestions' => ['Sheffield Station, Sheaf St, Sheffield S1']]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'places.googleapis.com')
            && ($r['input'] ?? null) === 'Sheffield Station');
    }

    public function test_the_booking_page_wires_the_address_fields_to_autocomplete(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $page = $this->get(route('public.book'))->assertOk();
        $page->assertSee('data-places', false);
        $page->assertSee('book/places', false);      // the proxy URL is set
        $page->assertSee('js/cet-forms.js', false);  // the helper is loaded
    }
}
