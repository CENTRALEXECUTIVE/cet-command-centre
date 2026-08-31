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
        // The office note the driver needs to see must reach every car.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['driver_notes' => 'Ring the buzzer for flat 3'])])->save();

        $this->actingAs($admin)
            ->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones', 'phone' => '07700900222', 'car' => 'Black V-Class'])
            ->assertRedirect();

        $booking->refresh();
        $this->assertCount(1, $booking->extraDrivers());
        $this->assertSame(2, $booking->carCount());

        $token = $booking->extraDrivers()[0]['token'];
        // The extra-car link opens the job sheet for Car 2, with the office note.
        $this->get(route('driver.car', $token))->assertOk()
            ->assertSee('Car 2 of 2')
            ->assertSee('Nick Wedding')
            ->assertSee('Ring the buzzer for flat 3')
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

    public function test_each_car_can_carry_its_own_passenger_count(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Big Group'])->id,
                'pickup_at' => now()->addDay(),
                'passengers' => 10,
                'status' => BookingStatus::Allocated->value,
            ]);

        // Add two extra cars, one with a passenger split set on the form.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones', 'passengers' => '4'])->assertRedirect();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Lee Ford'])->assertRedirect();

        $booking->refresh();
        [$t2, $t3] = [$booking->extraDrivers()[0]['token'], $booking->extraDrivers()[1]['token']];

        $this->assertSame(4, $booking->extraDriverPassengers($t2));
        $this->assertNull($booking->extraDriverPassengers($t3));

        // Set the second car's count via the endpoint.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.passengers', $booking), ['token' => $t3, 'passengers' => '2'])->assertRedirect();
        $booking->refresh();
        $this->assertSame(2, $booking->extraDriverPassengers($t3));

        // Lead car takes the remainder: 10 − 4 − 2 = 4.
        $this->assertSame(4, $booking->leadCarPassengers());

        // The extra car's own link shows ITS count, not the whole party.
        $this->get(route('driver.car', $t2))->assertOk()
            ->assertSee('4 in your car');

        // Clearing a car's count falls back to the whole-party display.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.passengers', $booking), ['token' => $t2, 'passengers' => ''])->assertRedirect();
        $this->assertNull($booking->fresh()->extraDriverPassengers($t2));
    }

    public function test_the_lead_car_passenger_count_can_be_set_by_hand(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Big Group'])->id,
                'pickup_at' => now()->addDay(),
                'passengers' => 7,
                'status' => BookingStatus::Allocated->value,
            ]);
        // Two extra cars carry all 7 → lead auto-remainder is 0.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Car2', 'passengers' => '4']);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Car3', 'passengers' => '3']);
        $this->assertSame(0, $booking->fresh()->leadCarPassengers());

        // The office sets the lead car by hand — the override wins.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.passengers', $booking), ['token' => 'lead', 'passengers' => '3'])->assertRedirect();
        $booking->refresh();
        $this->assertTrue($booking->leadCarPassengersSet());
        $this->assertSame(3, $booking->leadCarPassengers());

        // The lead driver's own link shows the set count.
        $this->get(route('driver.link', $booking->driverLinkToken()))->assertOk()->assertSee('3 in your car');

        // Clearing it returns to the auto-remainder (0 here).
        $this->actingAs($admin)->post(route('bookings.extra-drivers.passengers', $booking), ['token' => 'lead', 'passengers' => ''])->assertRedirect();
        $booking->refresh();
        $this->assertFalse($booking->leadCarPassengersSet());
        $this->assertSame(0, $booking->leadCarPassengers());
    }

    public function test_all_cars_live_status_panel_shows_each_cars_progress(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Lead Larry']);
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Nick Wedding'])->id,
                'pickup_at' => now()->addDay(), 'driver_id' => $driver->id,
                'status' => BookingStatus::Allocated->value,
            ]);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        // Car 2 sets off then arrives — stamps recorded per stage.
        $this->post(route('driver.car.status', $token), ['status' => 'accepted'])->assertRedirect();
        $this->post(route('driver.car.status', $token), ['status' => 'en_route'])->assertRedirect();
        $this->post(route('driver.car.status', $token), ['status' => 'arrived'])->assertRedirect();

        $booking->refresh();
        $this->assertNotNull($booking->extraDriverStampAt($token, 'arrived'));

        // The booking page shows the consolidated panel with both cars.
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('All cars — live status')
            ->assertSee('Car 1 — Lead Larry')
            ->assertSee('Car 2 — Sam Jones')
            ->assertSee('1 of 2'); // Car 2 arrived, Car 1 not yet
    }

    public function test_extra_car_status_changes_notify_the_office(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        // Car 2 accepts (no ping) then sets off and arrives (office pinged).
        $this->post(route('driver.car.status', $token), ['status' => 'accepted'])->assertRedirect();
        $this->post(route('driver.car.status', $token), ['status' => 'en_route'])->assertRedirect();
        $this->post(route('driver.car.status', $token), ['status' => 'arrived'])->assertRedirect();

        // The office feed gets a set-off and an arrived event naming the car.
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id, 'event_type' => 'driver_set_off',
        ]);
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id, 'event_type' => 'driver_arrived',
        ]);
        $arrived = \App\Models\WatchdogEvent::where('booking_id', $booking->id)
            ->where('event_type', 'driver_arrived')->latest('id')->first();
        $this->assertStringContainsString('Car 2', (string) $arrived->title);
    }

    public function test_extra_car_pay_folds_into_the_drivers_own_payroll_row(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $kash = User::factory()->driver()->create(['name' => 'Kash']);

        // Kash's OWN lead job (pay £120).
        $lead = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Lead Cust'])->id,
                'driver_id' => $kash->id, 'pickup_at' => '2026-07-06 09:00',
                'status' => BookingStatus::Complete->value, 'payment_method' => 'card',
            ]);
        $this->actingAs($admin)->post(route('bookings.payroll', $lead), ['action' => 'set', 'amount' => '120']);

        // Kash ALSO covers an extra car on someone else's multi-car job (pay £95).
        $wedding = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Nick Wedding'])->id,
                'pickup_at' => '2026-07-06 14:00', 'status' => BookingStatus::Complete->value,
            ]);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $wedding), ['name' => 'Kash']);
        $token = $wedding->fresh()->extraDrivers()[0]['token'];
        $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $wedding), ['token' => $token, 'action' => 'set', 'amount' => '95']);

        $res = $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-07']))->assertOk();

        // ONE Kash row, combining both (£120 + £95 = £215) — no separate section.
        $res->assertSee('Kash')->assertSee('£215.00 total')->assertSee('extra car');
        $this->assertSame(1, substr_count($res->getContent(), '>Kash ↗<') + substr_count($res->getContent(), '>Kash<'));

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_extra_cars_section_stays_on_a_completed_job_for_pay_and_mark_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Wedding'])->id,
                'pickup_at' => now()->subDay(), 'status' => BookingStatus::Complete->value,
            ]);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Kash']);

        // Even though the job is completed, the office can still set pay / mark paid.
        $this->actingAs($admin)->get(route('bookings.show', $booking->fresh()))->assertOk()
            ->assertSee('Extra cars — multi-car job')
            ->assertSee('Set pay')
            ->assertSee('Passengers in this car');
    }

    public function test_an_extra_car_can_be_marked_paid_from_the_payroll_page(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20 12:00:00');
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Wedding'])->id,
                'pickup_at' => '2026-07-06 14:00', 'status' => BookingStatus::Complete->value,
            ]);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Kash']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];
        $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $booking), ['token' => $token, 'action' => 'set', 'amount' => '95']);

        // Payroll offers a Mark paid button while it's owed.
        $this->actingAs($admin)->get(route('payroll.index', ['month' => '2026-07']))->assertOk()
            ->assertSee('Mark paid');

        // Record the full remaining as paid → settled.
        $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $booking), [
            'token' => $token, 'action' => 'record', 'amount' => '95',
        ])->assertRedirect();

        $this->assertSame(0.0, $booking->fresh()->extraDriverPayRemaining($token));

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_driver_cost_includes_extra_car_pay_so_margin_is_not_overstated(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Richard']);
        // A 3-car wedding leg: fare £325, lead paid £110, two extra cars £95 each.
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Nick'])->id,
                'driver_id' => $driver->id, 'pickup_at' => now()->subDay(),
                'status' => BookingStatus::Complete->value, 'payment_method' => 'card',
                'final_price' => 325,
            ]);
        $this->actingAs($admin)->post(route('bookings.payroll', $booking), ['action' => 'set', 'amount' => '110']);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Kash']);
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam']);
        $booking->refresh();
        foreach ($booking->extraDrivers() as $d) {
            $this->actingAs($admin)->post(route('bookings.extra-drivers.payroll', $booking), ['token' => $d['token'], 'action' => 'set', 'amount' => '95']);
        }

        // Cost = £110 + £95 + £95 = £300, not just the lead's £110.
        $this->assertSame(300.0, $booking->fresh()->driverCost());

        // Margin on the job is £325 − £300 = £25, not £215.
        $reports = app(\App\Services\Reporting\ReportService::class)->profit(now()->subMonth(), now());
        $richard = collect($reports['per_driver'])->firstWhere('name', 'Richard');
        $this->assertSame(25.0, $richard['profit']);
    }

    public function test_extra_car_endpoints_are_admin_only_for_management(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->booking();
        $this->actingAs($driver)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'X'])->assertForbidden();
    }
}
