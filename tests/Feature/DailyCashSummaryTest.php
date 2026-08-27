<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCashSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_nets_cash_and_pay_to_a_single_settle_figure_per_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Abdi Ali']);
        $today = now()->setTime(10, 0);

        // Cash job: £100 fare, driver keeps £70 → collected £100, owes £30 back.
        $cash = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete,
            'pickup_at' => $today, 'payment_method' => PaymentMethod::Cash->value,
            'quoted_price' => 100, 'final_price' => 100,
        ]);
        $cash->forceFill(['meta' => ['payroll' => ['pay' => 70, 'paid' => 0, 'history' => []]]])->save();

        // Card job: business took the money, owes the driver £40 pay.
        $card = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete,
            'pickup_at' => $today->copy()->addHour(), 'payment_method' => PaymentMethod::Card->value,
            'payment_status' => 'paid', 'quoted_price' => 120, 'final_price' => 120,
        ]);
        $card->forceFill(['meta' => ['payroll' => ['pay' => 40, 'paid' => 0, 'history' => []]]])->save();

        $res = $this->actingAs($admin)->get(route('payroll.daily', ['date' => $today->format('Y-m-d')]));
        $res->assertOk()->assertSee('Abdi Ali')->assertSee('Cash &amp; pay', false);

        // Net per driver: (70 − 100) + 40 = +10 → business pays Abdi £10 net.
        $res->assertSee('+£10.00');
        // Cash collected total = £100.
        $res->assertSee('£100.00');
    }

    public function test_a_driver_who_over_collected_cash_shows_as_collect_back(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Maj Khan']);
        $today = now()->setTime(9, 0);

        // Only a cash job where the driver collected £120 but their pay is £80.
        $cash = Booking::factory()->create([
            'driver_id' => $driver->id, 'status' => BookingStatus::Complete,
            'pickup_at' => $today, 'payment_method' => PaymentMethod::Cash->value,
            'quoted_price' => 120, 'final_price' => 120,
        ]);
        $cash->forceFill(['meta' => ['payroll' => ['pay' => 80, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($admin)->get(route('payroll.daily', ['date' => $today->format('Y-m-d')]))
            ->assertOk()
            ->assertSee('Collect from Maj Khan')
            ->assertSee('−£40.00'); // 80 − 120 = −40
    }

    public function test_only_admins_can_view_the_daily_cash_summary(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('payroll.daily'))->assertForbidden();
    }
}
