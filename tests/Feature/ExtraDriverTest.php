<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-car jobs (e.g. a 3-car wedding): the office adds extra drivers, each
 * with their own link and their own per-car status, tracked separately.
 */
class ExtraDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Nick Wedding', 'phone' => '07700900111'])->id,
                'pickup_at' => now()->addDay(),
                'status' => BookingStatus::Allocated->value,
            ]);
    }

    public function test_outbound_can_match_cars_onto_a_chosen_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $outbound = $this->booking();
        // The return leg — SAME customer, not linked in the DB, chosen by hand.
        $return = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => $outbound->customer_id,
                'is_return_leg' => true, 'pickup_at' => now()->addDays(2),
                'status' => BookingStatus::Allocated->value,
            ]);

        $outbound->addExtraDriver(['name' => 'Sam Jones', 'phone' => '07700900001', 'reg' => 'AB19XYZ', 'car' => 'Black V Class']);
        $outbound->addExtraDriver(['name' => 'Lee Ford', 'phone' => '07700900002', 'reg' => 'CD20ABC', 'car' => 'Grey Estate']);

        // The outbound page offers the picker with the return as a target.
        $this->actingAs($admin)->get(route('bookings.show', $outbound->fresh()))
            ->assertOk()
            ->assertSee('Match these cars to another booking')
            ->assertSee($return->reference);

        // Pick that booking → both cars copied over.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.copy', $outbound->fresh()), [
            'target_booking_id' => $return->id,
        ])->assertRedirect();

        $return = $return->fresh();
        $names = collect($return->extraDrivers())->pluck('name')->all();
        $this->assertContains('Sam Jones', $names);
        $this->assertContains('Lee Ford', $names);
        // Each got its OWN fresh link token.
        $outboundTokens = collect($outbound->fresh()->extraDrivers())->pluck('token');
        $returnTokens = collect($return->extraDrivers())->pluck('token');
        $this->assertEmpty($outboundTokens->intersect($returnTokens));

        // Re-running doesn't duplicate.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.copy', $outbound->fresh()), ['target_booking_id' => $return->id])->assertRedirect();
        $this->assertCount(2, $return->fresh()->extraDrivers());
    }

    public function test_extra_driver_form_offers_a_driver_picker_and_typeahead(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Sam Jones']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'callsign' => 'Sam']);
        $booking = $this->booking();

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('ex-driver-pick', false)      // the dropdown
            ->assertSee('ex-driver-names', false)      // the type-ahead datalist
            ->assertSee('Sam');                        // the driver appears as an option
    }

    public function test_admin_adds_extra_cars_and_each_gets_its_own_link(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();

        $this->actingAs($admin)
            ->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones', 'phone' => '07700900222', 'car' => 'Black V-Class'])
            ->assertRedirect();

        $booking->refresh();
        $this->assertCount(1, $booking->extraDrivers());
        $this->assertSame(2, $booking->carCount());

        $token = $booking->extraDrivers()[0]['token'];
        // The extra-car link opens the job sheet for Car 2.
        $this->get(route('driver.car', $token))->assertOk()
            ->assertSee('Car 2 of 2')
            ->assertSee('Nick Wedding')
            ->assertSee('Accept job'); // first status button for an allocated car
    }

    public function test_extra_car_status_is_tracked_separately_from_the_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        // Car 2 driver accepts then sets off — only THIS car's status moves.
        $this->post(route('driver.car.status', $token), ['status' => 'accepted'])->assertRedirect();
        $this->post(route('driver.car.status', $token), ['status' => 'en_route'])->assertRedirect();

        $booking->refresh();
        $this->assertSame('en_route', $booking->extraDrivers()[0]['status']);
        // The main booking status is untouched.
        $this->assertSame(BookingStatus::Allocated, $booking->status);
    }

    public function test_an_illegal_car_step_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        // Can't jump allocated → complete.
        $this->post(route('driver.car.status', $token), ['status' => 'complete'])
            ->assertSessionHasErrors('status');
    }

    public function test_admin_can_remove_an_extra_car(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        $this->actingAs($admin)->post(route('bookings.extra-drivers.remove', $booking), ['token' => $token])->assertRedirect();
        $this->assertCount(0, $booking->fresh()->extraDrivers());
    }

    public function test_each_extra_car_is_paid_separately_and_shows_on_payroll(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Wedding Party'])->id,
                'pickup_at' => '2026-07-06 14:00', 'status' => BookingStatus::Complete->value,
            ]);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Car2 Carl']);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Car3 Cara']);
        $booking->refresh();
        [$t2, $t3] = [$booking->extraDrivers()[0]['token'], $booking->extraDrivers()[1]['token']];

        // Set each car's pay separately, then part-pay one.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $booking), ['token' => $t2, 'action' => 'set', 'amount' => '80'])->assertRedirect();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $booking), ['token' => $t3, 'action' => 'set', 'amount' => '90'])->assertRedirect();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $booking), ['token' => $t2, 'action' => 'record', 'amount' => '30'])->assertRedirect();

        $booking->refresh();
        $this->assertSame(80.0, $booking->extraDriverPay($t2));
        $this->assertSame(50.0, $booking->extraDriverPayRemaining($t2)); // 80 − 30
        $this->assertSame(90.0, $booking->extraDriverPayRemaining($t3)); // untouched

        // They appear on the payroll page as their own rows.
        $res = $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-07']))->assertOk();
        $res->assertSee('Car2 Carl')->assertSee('Car3 Cara')->assertSee('extra car', false);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_extra_car_endpoints_are_admin_only_for_management(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->booking();
        $this->actingAs($driver)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'X'])->assertForbidden();
    }
}
