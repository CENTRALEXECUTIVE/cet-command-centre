<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\BookingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Office-editable booking settings — saved values override config('cet.*') everywhere.
 */
class BookingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_settings_overrides_config_values(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('booking-settings.update'), [
            'min_lead_hours' => 12,
            'estate_uplift' => 15,
            'review_delay_minutes' => 45,
            'vat_registered' => '1',
            'vat_rate_percent' => 20,
            'driver_pay_percent' => 85,
            'terms_url' => 'https://example.com/terms',
            'ops_email' => 'ops@example.com',
        ])->assertRedirect()->assertSessionHas('status');

        // Stored.
        $this->assertSame(12, (int) Setting::get('booking.min_lead_hours'));
        $this->assertSame(85, (int) Setting::get('driver_pay_percent'));

        // Merged over config for the next request.
        BookingSettings::apply();
        $this->assertSame(12, (int) config('cet.public_min_lead_hours'));
        $this->assertEqualsWithDelta(15.0, (float) config('cet.estate_over_executive'), 0.01);
        $this->assertSame(45, (int) config('cet.review_delay_minutes'));
        $this->assertEqualsWithDelta(0.20, (float) config('cet.vat_rate'), 0.001);
        $this->assertSame('https://example.com/terms', config('cet.links.terms'));
        $this->assertSame('ops@example.com', config('cet.ops_email'));
    }

    public function test_the_page_shows_current_values(): void
    {
        $admin = User::factory()->admin()->create();
        config(['cet.public_min_lead_hours' => 8]);

        $this->actingAs($admin)->get(route('booking-settings.index'))->assertOk()
            ->assertSee('Booking settings')
            ->assertSee('Minimum online notice');
    }

    public function test_a_driver_cannot_access_booking_settings(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('booking-settings.index'))->assertForbidden();
        $this->actingAs($driver)->put(route('booking-settings.update'), [])->assertForbidden();
    }
}
