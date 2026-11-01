<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Jackie Donoghue, Nov 2026: her booking moved to Wed 04 Nov 18:00 but the queued
 * reminder stayed on Mon 18:00 (stamped from the old pickup). A queued reminder
 * must always follow the booking's CURRENT pickup.
 */
class ReminderFollowsPickupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/London']);
        date_default_timezone_set('Europe/London');
        $this->travelTo(Carbon::parse('2026-11-01 14:00:00', 'Europe/London'));
    }

    private function queuedReminder(Booking $b)
    {
        return $b->messages()->where('type', 'reminder_24h')->where('status', 'queued')->first();
    }

    public function test_moving_a_booking_moves_its_queued_reminder(): void
    {
        $customer = Customer::create(['name' => 'Jackie Donoghue', 'phone' => '07700900111']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id, 'status' => 'allocated',
            'pickup_at' => Carbon::parse('2026-11-03 18:00', 'Europe/London'), // Tue
        ]);
        app(BookingNotifier::class)->ensureReminders($b);
        $this->assertSame('2026-11-02 18:00', $this->queuedReminder($b)->scheduled_for->format('Y-m-d H:i')); // Mon

        // Moved to Wednesday → the reminder moves to Tuesday 18:00.
        $b->update(['pickup_at' => Carbon::parse('2026-11-04 18:00', 'Europe/London')]);
        $this->assertSame('2026-11-03 18:00', $this->queuedReminder($b->fresh())->scheduled_for->format('Y-m-d H:i'));
    }

    public function test_an_early_pickup_still_gets_its_daytime_reminder_after_a_move(): void
    {
        $customer = Customer::create(['name' => 'Matthew Shaw', 'phone' => '07989605549']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id, 'status' => 'allocated',
            'pickup_at' => Carbon::parse('2026-11-05 04:15', 'Europe/London'),
        ]);
        app(BookingNotifier::class)->ensureReminders($b);

        $b->update(['pickup_at' => Carbon::parse('2026-11-04 04:15', 'Europe/London')]);
        // Wed 04:15 → day before is Tue 04:15, pulled into the send window → Tue 08:00.
        $this->assertSame('2026-11-03 08:00', $this->queuedReminder($b->fresh())->scheduled_for->format('Y-m-d H:i'));
    }

    public function test_the_prepare_pass_corrects_a_reminder_left_on_the_old_day(): void
    {
        $customer = Customer::create(['name' => 'Jackie Donoghue', 'phone' => '07700900111']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id, 'status' => 'allocated',
            'pickup_at' => Carbon::parse('2026-11-04 18:00', 'Europe/London'),
        ]);
        app(BookingNotifier::class)->ensureReminders($b);
        // Simulate a reminder stamped from an old pickup (moved before this fix).
        $this->queuedReminder($b)->forceFill(['scheduled_for' => Carbon::parse('2026-11-02 18:00', 'Europe/London')])->save();

        $this->artisan('cet:prepare-reminders')->assertSuccessful();

        $this->assertSame('2026-11-03 18:00', $this->queuedReminder($b->fresh())->scheduled_for->format('Y-m-d H:i'));
    }
}
