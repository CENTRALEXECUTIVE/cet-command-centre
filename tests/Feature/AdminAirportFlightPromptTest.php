<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors (24 Nov 2026): airport pickup on the office form -> flight number right under the pickup time, flagged, never blocking. */
class AdminAirportFlightPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_booking_form_moves_and_flags_the_flight_number_for_an_airport_pickup(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $html = $this->actingAs($admin)->get(route('bookings.create'))->assertOk()->getContent();

        $this->assertStringContainsString('id="flight-needed"', $html);
        $this->assertStringContainsString('function placeFlightFields(arrival)', $html);
        $this->assertStringContainsString('You can still save without it', $html);
        // Never a hard requirement on the office form.
        $this->assertStringContainsString('flightIn.required = false;', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="flight_number"[^>]*required/', $html);
    }
}
