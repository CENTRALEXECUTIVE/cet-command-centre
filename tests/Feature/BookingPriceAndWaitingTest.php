<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Quick edits from the booking page: the job price and the billable waiting time,
 * plus the safety cap that stops a forgotten job inventing a huge waiting charge.
 */
class BookingPriceAndWaitingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /* ── Price ───────────────────────────────────────────────────────────── */

    public function test_admin_can_set_the_job_price(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 95, 'final_price' => null]);

        $this->actingAs($this->admin())
            ->post(route('bookings.price', $b), ['final_price' => '100'])
            ->assertRedirect();

        $this->assertSame(100.0, (float) $b->fresh()->final_price);
        $this->assertSame(100.0, $b->fresh()->fareAmount());
    }

    public function test_admin_can_clear_the_price_back_to_the_quote(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 95, 'final_price' => 120]);

        $this->actingAs($this->admin())
            ->post(route('bookings.price', $b), ['final_price' => ''])
            ->assertRedirect();

        $this->assertNull($b->fresh()->final_price);
        $this->assertSame(95.0, $b->fresh()->fareAmount());
    }

    public function test_a_driver_cannot_set_the_price(): void
    {
        $b = Booking::factory()->create();
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.price', $b), ['final_price' => '100'])
            ->assertForbidden();
    }

    /* ── Waiting ─────────────────────────────────────────────────────────── */

    public function test_admin_can_set_billable_waiting_minutes(): void
    {
        $b = Booking::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('bookings.waiting', $b), ['waiting_minutes' => '60'])
            ->assertRedirect();

        $b->refresh();
        $this->assertSame(60, $b->recordedWaitingMinutes());
        $this->assertGreaterThan(0, $b->waitingCharge());
    }

    public function test_admin_can_remove_the_waiting_charge_even_after_completion(): void
    {
        $b = Booking::factory()->create([
            'status' => BookingStatus::Complete->value,
            'meta' => ['waiting' => ['billable_minutes' => 400]],
        ]);
        $this->assertGreaterThan(0, $b->waitingCharge());

        $this->actingAs($this->admin())
            ->post(route('bookings.waiting', $b), ['clear' => '1'])
            ->assertRedirect();

        $this->assertSame(0.0, $b->fresh()->waitingCharge());
    }

    public function test_auto_waiting_is_capped_when_a_driver_forgets_to_progress_the_job(): void
    {
        config(['cet.waiting_max_auto_minutes' => 180]);

        $driver = User::factory()->driver()->create();
        // Pickup two days ago, arrived then, GPS confirms at pickup — but the job
        // was never progressed, so the raw elapsed is ~2 days.
        $pickup = [53.4, -1.5];
        $b = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Arrived->value,
            'pickup_at' => now()->subDays(2),
            'meta' => ['geo' => ['pickup' => $pickup]],
        ]);
        $arrived = now()->subDays(2)->addMinutes(1);
        $b->statusHistory()->create(['to_status' => 'arrived', 'created_at' => $arrived]);
        DriverLocation::create([
            'driver_id' => $driver->id, 'booking_id' => $b->id,
            'latitude' => $pickup[0], 'longitude' => $pickup[1], 'accuracy' => 10,
            'captured_at' => $arrived->copy()->addMinutes(1),
        ]);

        // Raw would be thousands of minutes; the cap holds it at 180.
        $this->assertSame(180, $b->fresh()->waitingBillableMinutes());
    }

    /* ── Tip message body ────────────────────────────────────────────────── */

    public function test_a_tip_request_always_renders_the_tip_message_even_if_saved_wrong(): void
    {
        $b = Booking::factory()->create();
        $msg = Message::create([
            'booking_id' => $b->id, 'customer_id' => $b->customer_id,
            'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'tip_request',
            'to_address' => '07700900123', 'status' => 'queued',
            'body' => 'OLD WRONG BODY — leave us a review on Google', // mis-saved
        ]);

        $rendered = $msg->renderedBody();
        $this->assertStringNotContainsString('leave us a review', $rendered);
        $this->assertStringContainsString('/tip/', $rendered); // the tip link
    }
}
