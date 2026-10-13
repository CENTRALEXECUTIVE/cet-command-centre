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
        // Credentials present but no masked session AND no switchboard lines set →
        // empty, no outbound Twilio call needed.
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

    public function test_switchboard_calls_are_attributed_to_the_right_party(): void
    {
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'token_test',
            'services.twilio_masking.customer_line' => '+441140000001',
            'services.twilio_masking.driver_line' => '+441140000002',
        ]);

        $customer = \App\Models\Customer::factory()->create(['phone' => '07700900111']);
        $driver = User::factory()->driver()->create(['phone' => '07700900222']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'driver_id' => $driver->id,
            'pickup_at' => now()->addHour(),
        ]);

        // One call: the CUSTOMER rang the customer line (reaching their driver).
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (str_contains($request->url(), 'Calls.json')
                && str_contains($request->url(), 'From=%2B447700900111')) {
                return \Illuminate\Support\Facades\Http::response(['calls' => [[
                    'sid' => 'CA1', 'to' => '+441140000001', 'from' => '+447700900111',
                    'direction' => 'inbound', 'start_time' => now()->toRfc2822String(),
                    'duration' => '134', 'status' => 'completed',
                ]]]);
            }

            return \Illuminate\Support\Facades\Http::response(['calls' => [], 'messages' => []]);
        });

        $calls = app(\App\Services\Telephony\TwilioMessageLog::class)->callsForBooking($booking->fresh());

        $this->assertCount(1, $calls);
        $this->assertSame('Customer', $calls[0]['caller']);
        $this->assertSame('Driver', $calls[0]['callee']);
        $this->assertSame(134, $calls[0]['duration']);
    }
}
