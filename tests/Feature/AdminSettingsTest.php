<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Pricing\DistanceService;
use App\Services\Telephony\MaskingService;
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

    public function test_admin_can_set_the_masking_numbers_in_app(): void
    {
        $admin = User::factory()->admin()->create();
        config(['services.twilio_masking.customer_line' => '+447700159929']); // old env value

        // The in-app setting overrides the env customer line.
        $this->actingAs($admin)->put(route('settings.update'), [
            'twilio_customer_line' => '+447575583899',
            'twilio_driver_line' => '',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertEquals('+447575583899', app(MaskingService::class)->customerLine());
        // Blank driver line falls back to the configured value.
        config(['services.twilio_masking.driver_line' => '+447700111222']);
        $this->assertEquals('+447700111222', app(MaskingService::class)->driverLine());
    }

    public function test_settings_page_shows_the_twilio_webhook_urls(): void
    {
        $admin = User::factory()->admin()->create();
        config(['app.url' => 'https://staging.centralexecutivetransfers.co.uk', 'cet.webhook_secret' => 'sek']);

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Phone lines')
            ->assertSee('https://staging.centralexecutivetransfers.co.uk/webhooks/sms?secret=sek')
            ->assertSee('https://staging.centralexecutivetransfers.co.uk/webhooks/voice?secret=sek');
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('settings.index'))->assertForbidden();
    }
}
