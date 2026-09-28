<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlacesAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_suggestions_from_google_places(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('google_maps_key', 'AIzaTEST', 'string', 'integrations');

        Http::fake(['places.googleapis.com/*' => Http::response([
            'suggestions' => [
                ['placePrediction' => ['text' => ['text' => 'Sheffield Town Hall, Pinstone Street, Sheffield, UK']]],
                ['placePrediction' => ['text' => ['text' => 'Sheffield Station, Sheaf Street, Sheffield, UK']]],
            ],
        ], 200)]);

        $this->actingAs($admin)->getJson(route('places.autocomplete', ['q' => 'Sheffield']))
            ->assertOk()
            ->assertJsonCount(2, 'suggestions')
            ->assertJsonFragment(['Sheffield Town Hall, Pinstone Street, Sheffield, UK']);
    }

    public function test_empty_without_a_key_or_short_query(): void
    {
        $admin = User::factory()->admin()->create();

        // No key set.
        $this->actingAs($admin)->getJson(route('places.autocomplete', ['q' => 'Sheffield']))
            ->assertOk()->assertExactJson(['suggestions' => []]);

        // Key set but query too short (a single character).
        Setting::set('google_maps_key', 'AIzaTEST', 'string', 'integrations');
        Http::fake();
        $this->actingAs($admin)->getJson(route('places.autocomplete', ['q' => 'S']))
            ->assertOk()->assertExactJson(['suggestions' => []]);
        Http::assertNothingSent();
    }

    public function test_a_two_character_postcode_prefix_is_searched(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('google_maps_key', 'AIzaTEST', 'string', 'integrations');

        Http::fake(['places.googleapis.com/*' => Http::response([
            'suggestions' => [
                ['placePrediction' => ['text' => ['text' => 'Harney Close, Darnall, Sheffield S9 5BW, UK']]],
            ],
        ], 200)]);

        // "S9" (two chars) must reach Google so the postcode type-ahead can suggest.
        $this->actingAs($admin)->getJson(route('places.autocomplete', ['q' => 'S9']))
            ->assertOk()->assertJsonCount(1, 'suggestions');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'places.googleapis.com'));
    }

    public function test_address_types_bias_the_search_to_precise_addresses(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('google_maps_key', 'AIzaTEST', 'string', 'integrations');

        Http::fake(['places.googleapis.com/*' => Http::response([
            'suggestions' => [
                ['placePrediction' => ['text' => ['text' => '12 Harney Close, Darnall, Sheffield S9 5BW, UK']]],
            ],
        ], 200)]);

        $this->actingAs($admin)->getJson(route('places.autocomplete', ['q' => '12 Harney', 'types' => 'address']))
            ->assertOk()->assertJsonFragment(['12 Harney Close, Darnall, Sheffield S9 5BW, UK']);

        Http::assertSent(fn ($r) => is_array($r['includedPrimaryTypes'] ?? null)
            && in_array('street_address', $r['includedPrimaryTypes'], true));
    }

    public function test_address_search_falls_back_to_unrestricted_when_nothing_matches(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('google_maps_key', 'AIzaTEST', 'string', 'integrations');

        // First (restricted) call returns nothing; the unrestricted retry finds it.
        Http::fakeSequence('places.googleapis.com/*')
            ->push(['suggestions' => []], 200)
            ->push(['suggestions' => [
                ['placePrediction' => ['text' => ['text' => 'Radisson Blu Hotel, Sheffield, UK']]],
            ]], 200);

        $this->actingAs($admin)->getJson(route('places.autocomplete', ['q' => 'Radisson', 'types' => 'address']))
            ->assertOk()->assertJsonFragment(['Radisson Blu Hotel, Sheffield, UK']);
    }

    public function test_drivers_cannot_use_it(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->getJson(route('places.autocomplete', ['q' => 'Sheffield']))->assertForbidden();
    }
}
