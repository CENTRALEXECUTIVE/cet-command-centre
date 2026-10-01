<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\SquareBookingPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Square payment keys can be pasted in-app (no .env/deploy), and the booking payment
 * service reads them (in-app wins over config).
 */
class SquareSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasting_square_keys_enables_card_payments(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFalse(app(SquareBookingPaymentService::class)->enabled());

        $this->actingAs($admin)->put(route('settings.update'), [
            'square_environment' => 'sandbox',
            'square_app_id' => 'sq0idp-test',
            'square_access_token' => 'EAAA-test-token',
            'square_location_id' => 'LTEST123',
            'square_webhook_signature_key' => 'whsec-test',
        ])->assertRedirect();

        $this->assertSame('EAAA-test-token', Setting::get('square_access_token'));
        $this->assertTrue(app(SquareBookingPaymentService::class)->enabled());
    }

    public function test_leaving_a_secret_blank_keeps_the_saved_key(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('square_access_token', 'EAAA-existing', 'string', 'payments');
        Setting::set('square_location_id', 'LEXISTING', 'string', 'payments');

        // Save other settings, leaving the Square token blank.
        $this->actingAs($admin)->put(route('settings.update'), [
            'square_access_token' => '',
            'square_location_id' => '',
        ])->assertRedirect();

        $this->assertSame('EAAA-existing', Setting::get('square_access_token'));
        $this->assertSame('LEXISTING', Setting::get('square_location_id'));
    }

    public function test_the_settings_page_shows_the_square_webhook_url(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('settings.index'))->assertOk()
            ->assertSee('Square card payments')
            ->assertSee('/webhooks/square');
    }
}
