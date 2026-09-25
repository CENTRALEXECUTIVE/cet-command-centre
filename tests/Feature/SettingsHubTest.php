<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The one Settings hub gathers every office control into one landing page. */
class SettingsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hub_links_the_main_control_areas(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.hub'))
            ->assertOk()
            ->assertSee('Vehicles')
            ->assertSee('Free-roam rates')
            ->assertSee('Extra prices')
            ->assertSee('Fixed prices')
            ->assertSee(route('vehicles.index'))
            ->assertSee(route('extras.index'));
    }

    public function test_the_notifications_card_is_super_admin_only(): void
    {
        $plainAdmin = User::factory()->admin()->create(['is_super_admin' => false]);
        $this->actingAs($plainAdmin)->get(route('settings.hub'))->assertOk()
            ->assertDontSee(route('notifications.index'));

        $super = User::factory()->admin()->create(['is_super_admin' => true]);
        $this->actingAs($super)->get(route('settings.hub'))->assertOk()
            ->assertSee(route('notifications.index'));
    }

    public function test_a_non_admin_cannot_open_the_hub(): void
    {
        $this->actingAs(User::factory()->corporateClient()->create())
            ->get(route('settings.hub'))->assertForbidden();
    }
}
