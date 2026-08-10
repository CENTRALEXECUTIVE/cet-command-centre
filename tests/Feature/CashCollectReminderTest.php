<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drivers on a cash job are reminded to collect the cash and tap OK to
 * acknowledge. The amount is whatever's due on the booking (not a fixed figure).
 */
class CashCollectReminderTest extends TestCase
{
    use RefreshDatabase;

    private function cashJob(User $driver, float $fare = 90): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Accepted,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_status' => 'pending',
            'quoted_price' => $fare,
            'final_price' => $fare,
        ]);
    }

    public function test_the_link_shows_the_collect_the_cash_reminder(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->cashJob($driver, 90);

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('Collect the cash')
            ->assertSee('£90')
            ->assertSee('OK — I’ll collect it', false);
    }

    public function test_a_prepaid_card_job_shows_no_cash_reminder(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Accepted,
            'payment_method' => PaymentMethod::Card->value,
            'payment_status' => 'paid',
            'quoted_price' => 120, 'final_price' => 120,
        ]);

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertDontSee('Collect the cash');
    }

    public function test_driver_acknowledges_the_reminder_via_the_link(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->cashJob($driver, 90);
        $this->assertFalse($booking->cashCollectAcknowledged());

        $this->post(route('driver.link.ack-cash', $booking->driverLinkToken()))
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertTrue($booking->cashCollectAcknowledged());
        $this->assertSame(90.0, (float) $booking->meta['cash_ack']['amount']);

        // The reminder now shows the confirmed state, not the OK button.
        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('Cash to collect — confirmed')
            ->assertDontSee('OK — I’ll collect it', false);
    }

    public function test_logged_in_driver_acknowledges_the_reminder(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->cashJob($driver, 75);

        $this->actingAs($driver)
            ->post(route('driver.job.ack-cash', $booking))
            ->assertRedirect();

        $this->assertTrue($booking->fresh()->cashCollectAcknowledged());
    }

    public function test_a_driver_cannot_acknowledge_someone_elses_job(): void
    {
        $driver = User::factory()->driver()->create();
        $other = User::factory()->driver()->create();
        $booking = $this->cashJob($driver, 60);

        $this->actingAs($other)
            ->post(route('driver.job.ack-cash', $booking))
            ->assertForbidden();

        $this->assertFalse($booking->fresh()->cashCollectAcknowledged());
    }

    public function test_a_changed_amount_re_prompts_the_driver(): void
    {
        $driver = User::factory()->driver()->create();
        $booking = $this->cashJob($driver, 90);

        $booking->acknowledgeCashCollect($driver);
        $this->assertTrue($booking->fresh()->cashCollectAcknowledged());

        // The fare changes → the old acknowledgement no longer applies.
        $booking->forceFill(['final_price' => 120])->save();
        $this->assertFalse($booking->fresh()->cashCollectAcknowledged());
    }

    public function test_the_office_sees_the_cash_confirmation_on_the_booking_page(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $booking = $this->cashJob($driver, 90);

        // Before acknowledgement.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('not confirmed');

        $booking->acknowledgeCashCollect($driver);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('driver ✓', false);
    }
}
