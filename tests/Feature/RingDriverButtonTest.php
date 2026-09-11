<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The manual "Ring driver to update" button on a booking: the operator taps it and
 * the system phones the assigned DRIVER (never the customer) with a spoken message
 * asking them to open the app and update their status.
 */
class RingDriverButtonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function liveJob(?User $driver): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $driver?->id,
            'status' => BookingStatus::EnRoute->value,
            'pickup_at' => now()->addMinutes(20),
        ]);
    }

    private function configureTwilio(): void
    {
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);
    }

    public function test_it_calls_the_driver_and_confirms(): void
    {
        $this->configureTwilio();
        $driver = User::factory()->driver()->create(['phone' => '+447700900123', 'name' => 'Sam']);
        $booking = $this->liveJob($driver);

        $this->actingAs($this->admin())
            ->post(route('bookings.ring-driver', $booking))
            ->assertRedirect()
            ->assertSessionHas('status');

        // A call was placed to the driver's number, not the customer.
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'api.twilio.com')
                && $req['To'] === '+447700900123';
        });
    }

    public function test_it_reports_when_calling_is_not_configured(): void
    {
        // No Twilio config → no call, and a helpful error instead of silence.
        $driver = User::factory()->driver()->create(['phone' => '+447700900123']);
        $booking = $this->liveJob($driver);

        $this->actingAs($this->admin())
            ->post(route('bookings.ring-driver', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_it_wont_ring_a_driver_with_no_number(): void
    {
        $this->configureTwilio();
        $driver = User::factory()->driver()->create(['phone' => null]);
        $booking = $this->liveJob($driver);

        $this->actingAs($this->admin())
            ->post(route('bookings.ring-driver', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_it_wont_ring_when_there_is_no_live_driver(): void
    {
        $this->configureTwilio();
        $booking = $this->liveJob(null);

        $this->actingAs($this->admin())
            ->post(route('bookings.ring-driver', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_it_wont_ring_once_the_job_is_finished(): void
    {
        $this->configureTwilio();
        $driver = User::factory()->driver()->create(['phone' => '+447700900123']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Complete->value,
            'pickup_at' => now()->subHour(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('bookings.ring-driver', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_a_driver_cannot_trigger_the_call(): void
    {
        $this->configureTwilio();
        $driver = User::factory()->driver()->create(['phone' => '+447700900123']);
        $booking = $this->liveJob($driver);

        $this->actingAs($driver)
            ->post(route('bookings.ring-driver', $booking))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
