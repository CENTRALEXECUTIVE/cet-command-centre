<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Pricing\DistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_saves_maps_key_and_it_activates_distance_service(): void
    {
        $admin = User::factory()->admin()->create();
        config(['services.google_maps.key' => null]);

        $this->assertFalse(app(DistanceService::class)->configured());

        $this->actingAs($admin)->put(route('settings.update'), ['google_maps_key' => 'AIzaTESTKEY'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertEquals('AIzaTESTKEY', Setting::mapsKey());
        $this->assertTrue(app(DistanceService::class)->configured());
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('settings.index'))->assertForbidden();
    }
}
