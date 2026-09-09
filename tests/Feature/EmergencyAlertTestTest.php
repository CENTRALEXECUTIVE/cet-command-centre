<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchdogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "test the emergency alert" button: a super-admin can fire a critical test
 * alert (dashboard siren + push) and a test call to the office line.
 */
class EmergencyAlertTestTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->admin()->create(['is_super_admin' => true]);
    }

    public function test_it_fires_a_critical_test_event_and_places_a_call(): void
    {
        config([
            'services.twilio.sid' => 'AC1', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+447405172435',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        $this->actingAs($this->superAdmin())
            ->post(route('notifications.test'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Test call placed'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json'));
        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'test_alert', 'severity' => 'critical']);
    }

    public function test_it_reports_when_the_call_is_not_configured(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('notifications.test'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'not set up'));

        Http::assertNothingSent();
    }

    public function test_only_a_super_admin_can_fire_the_test(): void
    {
        $admin = User::factory()->admin()->create(['is_super_admin' => false]);

        $this->actingAs($admin)->post(route('notifications.test'))->assertForbidden();
    }
}
