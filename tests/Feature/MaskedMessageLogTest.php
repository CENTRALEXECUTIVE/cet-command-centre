<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The masked-line message transcript is super-admin only (it exposes the content
 * of private driver↔customer messages) and is a silent no-op when Twilio isn't
 * configured — it must never break the booking page.
 */
class MaskedMessageLogTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->admin()->create(['is_super_admin' => true]);
    }

    public function test_a_plain_admin_cannot_see_the_transcript(): void
    {
        $booking = Booking::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('bookings.messages', $booking))
            ->assertForbidden();
    }

    public function test_a_driver_cannot_see_the_transcript(): void
    {
        $booking = Booking::factory()->create();

        $this->actingAs(User::factory()->driver()->create())
            ->getJson(route('bookings.messages', $booking))
            ->assertForbidden();
    }

    public function test_super_admin_gets_a_graceful_empty_result_when_twilio_is_off(): void
    {
        config(['services.twilio.sid' => null, 'services.twilio.token' => null]);
        $booking = Booking::factory()->create();

        $this->actingAs($this->superAdmin())
            ->getJson(route('bookings.messages', $booking))
            ->assertOk()
            ->assertJson(['configured' => false, 'messages' => []]);
    }

    public function test_configured_but_no_sessions_returns_no_messages(): void
    {
        // Credentials present but the booking never had a masked session → empty,
        // no outbound Twilio call needed.
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'token_test',
        ]);
        $booking = Booking::factory()->create();

        $this->actingAs($this->superAdmin())
            ->getJson(route('bookings.messages', $booking))
            ->assertOk()
            ->assertJson(['configured' => true, 'messages' => []]);
    }
}
