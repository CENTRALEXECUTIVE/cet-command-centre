<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\JobNudge;
use App\Models\User;
use App\Services\Watchdog\StatusWatchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Directors (24 Nov 2026): a new web booking pushes 3 times (now, +2, +5 min) until
 * someone opens it; unpaid card/quote web bookings are never lost (dashboard box +
 * reminders) and are never cancelled automatically.
 */
class NewBookingAlertsAndAwaitingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function run_(): void
    {
        app(StatusWatchdog::class)->run();
    }

    private function nudges(Booking $b, string $type): int
    {
        return JobNudge::where('booking_id', $b->id)->where('nudge_type', 'like', $type)->count();
    }

    public function test_a_new_web_booking_repeats_twice_then_stops(): void
    {
        Carbon::setTestNow('2026-12-01 14:00:00');
        User::factory()->admin()->create(['is_super_admin' => true]);
        $b = Booking::factory()->create(['source' => 'web', 'status' => BookingStatus::Pending, 'payment_method' => 'cash',
            'payment_status' => 'pending', 'pickup_at' => now()->addDays(3), 'created_at' => now()]);

        Carbon::setTestNow(now()->addMinutes(1)); $this->run_();
        $this->assertSame(0, $this->nudges($b, 'new_booking_repeat'));
        Carbon::setTestNow(now()->addMinutes(2)); $this->run_();
        $this->assertSame(1, $this->nudges($b, 'new_booking_repeat'));
        Carbon::setTestNow(now()->addMinutes(3)); $this->run_();
        $this->assertSame(2, $this->nudges($b, 'new_booking_repeat'));
        Carbon::setTestNow(now()->addMinutes(3)); $this->run_();
        $this->assertSame(2, $this->nudges($b, 'new_booking_repeat'));
    }

    public function test_opening_the_booking_stops_the_repeats(): void
    {
        Carbon::setTestNow('2026-12-01 14:00:00');
        $admin = User::factory()->admin()->create(['is_super_admin' => true]);
        $b = Booking::factory()->create(['source' => 'web', 'status' => BookingStatus::Pending, 'payment_method' => 'cash',
            'payment_status' => 'pending', 'pickup_at' => now()->addDays(3), 'created_at' => now()]);

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk();
        Carbon::setTestNow(now()->addMinutes(3)); $this->run_();
        $this->assertSame(0, $this->nudges($b, 'new_booking_repeat'));
    }

    public function test_unpaid_card_booking_reminders_and_never_cancelled(): void
    {
        Carbon::setTestNow('2026-12-01 14:00:00');
        $admin = User::factory()->admin()->create(['is_super_admin' => true]);
        $b = Booking::factory()->create(['source' => 'web', 'status' => BookingStatus::Pending, 'payment_method' => 'card',
            'payment_status' => 'pending', 'quoted_price' => 105, 'pickup_at' => Carbon::parse('2026-12-03 20:30'),
            'created_at' => now(), 'meta' => ['seen_at' => now()->toIso8601String()]]);

        Carbon::setTestNow('2026-12-01 15:05:00'); $this->run_();
        $this->assertSame(1, $this->nudges($b, 'unpaid_1h'));
        Carbon::setTestNow('2026-12-02 09:10:00'); $this->run_();
        $this->assertSame(1, $this->nudges($b, 'unpaid_am_%'));
        Carbon::setTestNow('2026-12-02 21:00:00'); $this->run_();
        $this->assertSame(1, $this->nudges($b, 'unpaid_24h'));
        Carbon::setTestNow('2026-12-03 18:00:00'); $this->run_();
        $this->assertSame(1, $this->nudges($b, 'unpaid_3h'));

        $this->assertSame(BookingStatus::Pending, $b->fresh()->status);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Awaiting payment (1)')->assertSee('WhatsApp pay link')->assertSee('Mark paid');
    }

    public function test_a_paid_booking_leaves_the_box_and_gets_no_reminders(): void
    {
        Carbon::setTestNow('2026-12-01 14:00:00');
        $admin = User::factory()->admin()->create(['is_super_admin' => true]);
        $b = Booking::factory()->create(['source' => 'web', 'status' => BookingStatus::Pending, 'payment_method' => 'card',
            'payment_status' => 'paid', 'pickup_at' => now()->addDays(2), 'created_at' => now()->subHours(2),
            'meta' => ['seen_at' => now()->toIso8601String()]]);
        $this->run_();
        $this->assertSame(0, $this->nudges($b, 'unpaid_%'));
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('Awaiting payment (');
    }
}
