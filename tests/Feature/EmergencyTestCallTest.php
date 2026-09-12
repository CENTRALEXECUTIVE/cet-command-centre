<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "Test the emergency call only" button (Settings → Notifications) and the
 * booking-page green "driver has confirmed they're on it" badge.
 */
class EmergencyTestCallTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->admin()->create(['is_super_admin' => true]);
    }

    private function configureTwilio(): void
    {
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);
    }

    public function test_the_test_call_button_places_one_call_to_the_business_line(): void
    {
        $this->configureTwilio();

        $this->actingAs($this->superAdmin())
            ->post(route('notifications.test-call'))
            ->assertRedirect()
            ->assertSessionHas('status');

        // Exactly one call, to the office/business line.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r['To'] ?? '') === '+449999999999');
    }

    public function test_the_test_call_reports_when_twilio_is_not_set_up(): void
    {
        // No Twilio config → clear error, no call.
        $this->actingAs($this->superAdmin())
            ->post(route('notifications.test-call'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_regular_admin_cannot_fire_the_test_call(): void
    {
        $this->configureTwilio();
        $this->actingAs(User::factory()->admin()->create(['is_super_admin' => false]))
            ->post(route('notifications.test-call'))
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_booking_page_shows_when_the_driver_has_confirmed_theyre_on_it(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Sam Driver']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated->value,
            'pickup_at' => now()->addHour(),
        ]);

        // Before confirming — no green badge.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('confirmed they’re on it', false);

        $booking->confirmGettingReady($driver);

        // After confirming — the green badge shows.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('confirmed they’re on it', false);
    }
}
