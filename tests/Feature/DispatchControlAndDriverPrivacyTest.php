<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin power on the dispatch board (wind a status back, re-tie/un-tie the
 * driver at any stage) and driver privacy (no bookings area, no prices —
 * drivers see only their own job's facts and their own pay).
 */
class DispatchControlAndDriverPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function driver(string $name = 'Test Driver'): User
    {
        $driver = User::factory()->driver()->create(['name' => $name]);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        return $driver;
    }

    // ---- Admin power on the board -----------------------------------------

    public function test_admin_can_wind_a_status_back_after_a_wrong_tap(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        // Driver fat-fingered En Route…
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::EnRoute]);

        // …admin winds it back to Accepted from the board.
        $this->actingAs($admin)->post(route('despatch.quick-status', $booking), ['status' => 'accepted'])
            ->assertRedirect();

        $this->assertSame('accepted', $booking->fresh()->status->value);
        // And the override is on the record.
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'accepted',
            'note' => 'Admin override from the dispatch board',
        ]);
    }

    public function test_admin_can_move_a_job_to_another_driver_mid_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $wrong = $this->driver('Wrong Driver');
        $right = $this->driver('Right Driver');
        $booking = Booking::factory()->create(['driver_id' => $wrong->id, 'status' => BookingStatus::EnRoute]);

        $this->actingAs($admin)->post(route('despatch.reassign', $booking), ['driver_id' => $right->id])
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertSame($right->id, $booking->driver_id);
        $this->assertSame('en_route', $booking->status->value, 'A mid-flow swap keeps the job status.');
    }

    public function test_admin_can_untie_the_driver_and_return_the_job_to_the_pool(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::Accepted]);

        $this->actingAs($admin)->post(route('despatch.reassign', $booking), ['driver_id' => ''])
            ->assertRedirect();

        $booking = $booking->fresh();
        $this->assertNull($booking->driver_id);
        $this->assertSame('pending', $booking->status->value);
    }

    public function test_drivers_cannot_use_the_board_override_powers(): void
    {
        $driver = $this->driver();
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'status' => BookingStatus::EnRoute]);

        $this->actingAs($driver)->post(route('despatch.quick-status', $booking), ['status' => 'accepted'])
            ->assertForbidden();
        $this->actingAs($driver)->post(route('despatch.reassign', $booking), ['driver_id' => ''])
            ->assertForbidden();
    }

    // ---- Driver privacy -----------------------------------------------------

    public function test_drivers_are_locked_out_of_the_bookings_area(): void
    {
        $driver = $this->driver();
        $own = Booking::factory()->create(['driver_id' => $driver->id]);
        $other = Booking::factory()->create();

        // The bookings list bounces them to My jobs.
        $this->actingAs($driver)->get(route('bookings.index'))
            ->assertRedirect(route('driver.jobs'));

        // Their own booking's full page bounces to their driver job screen…
        $this->actingAs($driver)->get(route('bookings.show', $own))
            ->assertRedirect(route('driver.job', $own));

        // …someone else's booking bounces to the jobs list.
        $this->actingAs($driver)->get(route('bookings.show', $other))
            ->assertRedirect(route('driver.jobs'));
    }

    public function test_driver_sidebar_has_no_bookings_link(): void
    {
        $driver = $this->driver();

        $this->actingAs($driver)->get(route('driver.jobs'))
            ->assertOk()
            ->assertDontSee('📋 Bookings');
    }

    public function test_driver_job_screen_shows_the_job_facts_but_never_the_price(): void
    {
        $driver = $this->driver();
        $customer = \App\Models\Customer::create(['name' => 'Emma Cusworth', 'phone' => '07501028381']);
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Accepted,
            'customer_id' => $customer->id,
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'destination_address' => '5 Moorbridge Crescent, Barnsley',
            'passengers' => 5,
            'quoted_price' => 350.00,
            'final_price' => 350.00,
        ]);

        $this->actingAs($driver)->get(route('driver.job', $booking))
            ->assertOk()
            ->assertSee('Manchester Airport (MAN), Terminal 2')    // pickup
            ->assertSee('5 Moorbridge Crescent, Barnsley')         // drop-off
            ->assertDontSee('350')                                  // never the fare
            ->assertDontSee('£350');
    }

    public function test_driver_sees_cash_to_collect_on_a_cash_job(): void
    {
        $driver = $this->driver();
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Accepted,
            'payment_method' => \App\Enums\PaymentMethod::Cash->value,
            'payment_status' => 'pending',
            'quoted_price' => 90.00,
            'final_price' => 90.00,
        ]);

        $this->actingAs($driver)->get(route('driver.job', $booking))
            ->assertOk()
            ->assertSee('Collect')
            ->assertSee('£90.00')
            ->assertSee('to collect');
    }

    public function test_admin_can_assign_a_driver_from_the_booking_page(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        // The booking page offers the assignment control…
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()->assertSee('Assign a driver');

        // …and allocating from it ties the driver and moves the job to Allocated.
        $this->actingAs($admin)->post(route('despatch.allocate', $booking), ['driver_id' => $driver->id])
            ->assertRedirect();
        $this->assertSame($driver->id, $booking->fresh()->driver_id);
        $this->assertSame(BookingStatus::Allocated, $booking->fresh()->status);
    }

    public function test_driver_earnings_show_their_pay_not_the_customer_fare(): void
    {
        $driver = $this->driver();
        $booking = Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Complete,
            'pickup_at' => now()->subHours(2),
            'quoted_price' => 170.00,
            'final_price' => 170.00,
        ]);
        // The office set this job to pay the driver £40.
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 40, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($driver)->get(route('driver.earnings'))
            ->assertOk()
            ->assertSee('£40.00')       // their pay
            ->assertSee('Your pay')
            ->assertDontSee('170');     // never the customer fare
    }
}
