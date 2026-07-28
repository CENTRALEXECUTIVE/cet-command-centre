<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    private function validPayload(array $overrides = []): array
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        return array_merge([
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $executive->id,
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'pickup_address' => '12 Fargate, Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'luggage' => 2,
            'payment_method' => 'card',
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_admin_can_create_a_booking_with_full_side_effects(): void
    {
        $admin = User::factory()->admin()->create();
        $lhr = Airport::where('code', 'LHR')->first();

        $response = $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload([
            'airport_id' => $lhr->id,
            'flight_number' => 'BA1234',
        ]));

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $response->assertRedirect(route('bookings.show', $booking));

        // Customer remembered.
        $this->assertDatabaseHas('customers', ['name' => 'James Watson', 'phone' => '07700900123']);
        // GDPR consent captured.
        $this->assertDatabaseHas('consents', ['type' => 'privacy_notice', 'granted' => true]);
        // Calendar event scaffolded with +1h end and the card emoji.
        $this->assertNotNull($booking->calendarEvent);
        $this->assertEquals('👀', $booking->calendarEvent->payment_emoji);
        $this->assertEquals(
            $booking->pickup_at->copy()->addHour()->format('H:i'),
            $booking->calendarEvent->end_at->format('H:i')
        );
        // Executive job at LHR → ABDI allocated.
        $this->assertEquals('Abdirazak Hassan', $booking->driver->name);
    }

    public function test_bookings_list_can_be_searched(): void
    {
        $admin = User::factory()->admin()->create();
        $wanted = Booking::factory()->create(['external_reference' => 'FINDME1']);
        $other = Booking::factory()->create(['external_reference' => 'NOPE222']);

        $response = $this->actingAs($admin)->get(route('bookings.index', ['q' => 'FINDME1']))->assertOk();
        $response->assertSee($wanted->reference);
        $response->assertDontSee($other->reference);
    }

    public function test_bookings_list_can_be_viewed_by_month(): void
    {
        $admin = User::factory()->admin()->create();
        $july = Booking::factory()->create(['external_reference' => 'JULY01', 'pickup_at' => '2026-07-15 10:00']);
        $august = Booking::factory()->create(['external_reference' => 'AUG01', 'pickup_at' => '2026-08-03 10:00']);

        $response = $this->actingAs($admin)->get(route('bookings.index', ['month' => '2026-07']))->assertOk();
        $response->assertSee($july->reference);
        $response->assertDontSee($august->reference);
        $response->assertSee('July 2026', false);
    }

    public function test_the_bookings_count_excludes_cancelled_but_a_filter_can_show_them(): void
    {
        $admin = User::factory()->admin()->create();
        $live = Booking::factory()->create(['external_reference' => 'LIVE1', 'pickup_at' => now()->addDays(2), 'status' => \App\Enums\BookingStatus::Pending]);
        $cancelled = Booking::factory()->create(['external_reference' => 'CXL1', 'pickup_at' => now()->addDays(3), 'status' => \App\Enums\BookingStatus::Cancelled]);

        // Default browse view (All): cancelled is hidden and not counted.
        $res = $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'all']))->assertOk();
        $res->assertSee($live->reference)->assertDontSee($cancelled->reference);

        // Filtering by Cancelled surfaces it.
        $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'all', 'status' => 'cancelled']))
            ->assertOk()->assertSee($cancelled->reference)->assertDontSee($live->reference);

        // Filtering by a live status shows only that status.
        $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'all', 'status' => 'pending']))
            ->assertOk()->assertSee($live->reference)->assertDontSee($cancelled->reference);
    }

    public function test_paid_bookings_show_a_paid_flag_in_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $paid = Booking::factory()->create(['external_reference' => 'PAID1', 'pickup_at' => now()->addDay(), 'payment_status' => 'paid']);
        $unpaid = Booking::factory()->create(['external_reference' => 'OWES1', 'pickup_at' => now()->addDay(), 'payment_status' => 'pending']);

        $this->assertTrue($paid->isFullyPaid());
        $this->assertFalse($unpaid->isFullyPaid());

        $res = $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'upcoming']))->assertOk();
        $res->assertSee('💳 Paid');
    }

    public function test_a_deposit_plus_cash_booking_is_not_flagged_paid(): void
    {
        $b = Booking::factory()->make(['payment_status' => 'pending']);
        $b->forceFill(['meta' => ['payment_text' => 'Deposit £10 Paid – £90 Cash Due']]);

        $this->assertFalse($b->isFullyPaid()); // still money to collect
    }

    public function test_a_card_balance_job_is_not_flagged_paid_off_the_line(): void
    {
        // 👀 card-balance job (not settled) whose payment line mentions "paid".
        $card = Booking::factory()->make(['payment_status' => 'pending', 'payment_method' => \App\Enums\PaymentMethod::Card->value]);
        $card->forceFill(['meta' => ['payment_text' => '£100 to be paid by card link']]);
        $this->assertFalse($card->isFullyPaid());

        // A curated money emoji explicitly cleared → fully paid.
        $done = Booking::factory()->make(['payment_status' => 'pending', 'payment_method' => \App\Enums\PaymentMethod::Card->value]);
        $done->forceFill(['meta' => ['money_emoji' => '']]);
        $this->assertTrue($done->isFullyPaid());
    }

    public function test_bookings_can_be_filtered_by_driver_including_callsign_jobs(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::factory()->driver()->create(['name' => 'Abdirazak Hassan']);
        \App\Models\DriverProfile::create(['user_id' => $abdi->id, 'callsign' => 'Abdi']);

        // Allocated to his login…
        $mine = Booking::factory()->create(['external_reference' => 'MINE1', 'driver_id' => $abdi->id, 'pickup_at' => now()->addDay()]);
        // …and one tagged only with his callsign "Abdi" (no login link).
        $callsign = Booking::factory()->create(['external_reference' => 'CALL1', 'driver_id' => null, 'pickup_at' => now()->addDay()]);
        $callsign->forceFill(['meta' => ['driver_details' => ['name' => 'Abdi']]])->save();
        // Someone else's job — must not show.
        $other = Booking::factory()->create(['external_reference' => 'OTHER1', 'driver_id' => User::factory()->driver()->create()->id, 'pickup_at' => now()->addDay()]);

        $this->actingAs($admin)->get(route('bookings.index', ['driver' => 'Abdirazak Hassan', 'filter' => 'all']))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertSee($callsign->reference)   // callsign job included, matching the commission count
            ->assertDontSee($other->reference);
    }

    public function test_the_booking_page_links_back_to_the_list_view_you_came_from(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['pickup_at' => '2026-07-15 10:00']);

        // Visit a specific month view, then open a booking — the back link points
        // to that same month view, not the bare list.
        $this->actingAs($admin)->get(route('bookings.index', ['month' => '2026-07']));
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('← Back to bookings')
            ->assertSee('month=2026-07', false);
    }

    public function test_admin_can_export_the_current_view_as_csv(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::factory()->create(['external_reference' => 'EXP1', 'pickup_at' => '2026-07-15 10:00', 'quoted_price' => 120]);
        Booking::factory()->create(['external_reference' => 'AUGX', 'pickup_at' => '2026-08-01 10:00']);

        $res = $this->actingAs($admin)->get(route('bookings.export', ['month' => '2026-07']))->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Customer', $csv);   // header row
        $this->assertStringContainsString('EXP1', $csv);       // in the month
        $this->assertStringNotContainsString('AUGX', $csv);    // other month excluded
    }

    public function test_a_driver_cannot_export_bookings(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('bookings.export'))->assertForbidden();
    }

    public function test_unallocated_soon_appears_on_the_needs_attention_panel(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::factory()->create([
            'driver_id' => null, 'status' => \App\Enums\BookingStatus::Pending, 'pickup_at' => now()->addHour(),
        ]);

        $this->actingAs($admin)->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Unallocated');
    }

    public function test_the_list_offers_a_whatsapp_link_to_the_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['phone' => '+447700900123']);
        Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => now()->addDay()]);

        $this->actingAs($admin)->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('wa.me/447700900123');
    }

    public function test_the_list_remembers_your_last_filter(): void
    {
        $admin = User::factory()->admin()->create();

        // Choose the Past view…
        $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'past']))->assertOk();
        // …then land on /bookings bare → bounced back to the remembered filter.
        $this->actingAs($admin)->get(route('bookings.index'))
            ->assertRedirect(route('bookings.index', ['filter' => 'past']));
    }

    public function test_bookings_list_can_show_what_came_in_today(): void
    {
        $admin = User::factory()->admin()->create();
        $today = Booking::factory()->create(['external_reference' => 'TODAY1', 'pickup_at' => now()->addMonth()]);
        $old = Booking::factory()->create(['external_reference' => 'OLD1', 'pickup_at' => now()->addMonth()]);
        // OLD1 was created yesterday.
        Booking::where('id', $old->id)->update(['created_at' => now()->subDay()]);

        $response = $this->actingAs($admin)->get(route('bookings.index', ['filter' => 'booked-today']))->assertOk();
        $response->assertSee($today->reference);
        $response->assertDontSee($old->reference);
    }

    public function test_suitcases_and_hand_luggage_are_captured_separately(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload([
            'suitcases' => 3,
            'hand_luggage' => 2,
        ]));

        $booking = Booking::first();
        $this->assertEquals(3, $booking->meta['suitcases']);
        $this->assertEquals(2, $booking->meta['hand_luggage']);
        $this->assertEquals(5, $booking->luggage); // combined total kept in sync
        // The breakdown lands on the calendar and the booking mirrors it verbatim.
        $this->assertEquals('3 Suitcases + 2 Hand Luggage', $booking->luggageBreakdown());
    }

    public function test_luggage_breakdown_reads_the_calendar_luggage_text_for_older_bookings(): void
    {
        // An older booking with no discrete counts, but the descriptive text that
        // built its calendar event — the split must still show, mirroring the calendar.
        $booking = Booking::factory()->create([
            'luggage' => 3,
            'meta' => ['luggage_text' => '2 Suitcases + 1 Hand Luggage'],
        ]);

        $this->assertEquals('2 suitcases · 1 hand luggage', $booking->luggageBreakdown());
        $this->assertEquals('2 cases · 1 hand', $booking->luggageShort());
    }

    public function test_return_journey_creates_two_linked_legs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload([
            'journey_type' => 'return',
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
        ]));

        $this->assertEquals(2, Booking::count());
        $outbound = Booking::where('is_return_leg', false)->first();
        $return = Booking::where('is_return_leg', true)->first();

        $this->assertEquals($return->id, $outbound->linked_booking_id);
        $this->assertEquals($outbound->id, $return->linked_booking_id);
        // Return leg pickup = outbound destination.
        $this->assertEquals('Manchester Airport', $return->pickup_address);
    }

    public function test_return_fare_is_not_double_counted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload([
            'journey_type' => 'return',
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'quoted_price' => 100,
        ]));

        $outbound = Booking::where('is_return_leg', false)->first();
        $return = Booking::where('is_return_leg', true)->first();

        // The journey total sits on the outbound only — the return leg carries no
        // fare, so revenue counts £100 once (not £200).
        $this->assertEquals('100.00', (string) $outbound->quoted_price);
        $this->assertNull($return->quoted_price);
    }

    public function test_booking_requires_privacy_consent(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload(['privacy_consent' => null]))
            ->assertSessionHasErrors('privacy_consent');

        $this->assertEquals(0, Booking::count());
    }

    public function test_passenger_count_cannot_exceed_vehicle_capacity(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload(['passengers' => 6])) // Executive seats 4
            ->assertSessionHasErrors('passengers');
    }

    public function test_cost_code_is_mandatory_for_corporate_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CorporateAccount::create([
            'name' => 'JELD-WEN', 'slug' => 'jeld-wen', 'account_code' => 'JW1',
            'cost_code_required' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload([
                'corporate_account_id' => $account->id,
                'cost_code' => null,
            ]))
            ->assertSessionHasErrors('cost_code');
    }

    public function test_pickup_must_be_in_the_future(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload([
                'pickup_at' => now()->subHour()->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('pickup_at');
    }

    public function test_corporate_client_cannot_view_another_accounts_booking(): void
    {
        $account = CorporateAccount::create([
            'name' => 'LB Foster', 'slug' => 'lb-foster', 'account_code' => 'LB1',
            'cost_code_required' => false, 'is_active' => true,
        ]);
        $otherBooking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create();

        $client = User::factory()->corporateClient()->create();
        // Client belongs to a DIFFERENT account.
        $client->corporateAccounts()->attach($account->id);

        $this->actingAs($client)->get(route('bookings.show', $otherBooking))->assertForbidden();
    }

    public function test_admin_can_amend_a_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();
        $vClass = VehicleType::where('slug', 'v-class')->first() ?? VehicleType::where('slug', 'executive')->first();

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900999',
            'vehicle_type_id' => $vClass->id,
            'pickup_at' => now()->addDays(4)->format('Y-m-d\TH:i'),
            'pickup_address' => '1 New Street, Sheffield',
            'destination_address' => 'Leeds Bradford Airport',
            'via_stops' => ['Meadowhall Centre'],
            'passengers' => 3,
            'luggage' => 4,
            'payment_method' => 'cash',
            'quoted_price' => 145.50,
        ])->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();
        $this->assertEquals('1 New Street, Sheffield', $booking->pickup_address);
        $this->assertEquals('Leeds Bradford Airport', $booking->destination_address);
        $this->assertEquals(3, $booking->passengers);
        $this->assertEquals('145.50', (string) $booking->quoted_price);
        // Contact detail flowed to the customer record.
        $this->assertEquals('07700900999', $booking->customer->phone);
        // Via stop persisted.
        $this->assertEquals('Meadowhall Centre', $booking->stops()->first()->address);
        // Calendar event kept (updated in place, not duplicated).
        $this->assertEquals(1, $booking->calendarEvent()->count());
    }

    public function test_editing_a_booking_does_not_touch_the_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        // Pretend the calendar event has already been pushed/settled.
        $booking->calendarEvent->update(['sync_status' => 'synced']);

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'pickup_address' => 'New pickup point',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'payment_method' => 'card',
        ])->assertRedirect();

        // The edit must NOT re-queue a calendar push — sync_status stays 'synced'.
        $this->assertEquals('synced', $booking->calendarEvent->fresh()->sync_status);
    }

    public function test_adding_a_customer_number_opens_the_masked_line(): void
    {
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 't',
            'services.twilio.proxy_service_sid' => 'KS_test',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'proxy.twilio.com/v1/Services/KS_test/Sessions' => \Illuminate\Support\Facades\Http::response(['sid' => 'KC1']),
            'proxy.twilio.com/v1/Services/KS_test/Sessions/KC1/Participants' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['sid' => 'KPc', 'proxy_identifier' => '+447700111111'])
                ->push(['sid' => 'KPd', 'proxy_identifier' => '+447700222222']),
            'api.twilio.com/*' => \Illuminate\Support\Facades\Http::response(['sid' => 'SM']),
        ]);

        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver', 'phone' => '07700900222']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        // Allocated job whose customer has no number yet → no masked line.
        $booking = Booking::factory()->create([
            'status' => \App\Enums\BookingStatus::Allocated,
            'driver_id' => $driver->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'journey_type' => 'one_way',
            // Imminent (inside the "goes live" window) so the line opens as soon
            // as a number exists, rather than being deferred.
            'pickup_at' => now()->addMinutes(30),
        ]);
        $booking->customer->forceFill(['phone' => null])->save();
        $this->assertDatabaseCount('proxy_sessions', 0);

        // Add the customer's number through the edit form — the line opens.
        $this->actingAs($admin)->put(route('bookings.update', $booking), $this->validPayload([
            'customer_phone' => '07700900123',
            'pickup_at' => now()->addMinutes(30)->format('Y-m-d\TH:i'), // inside the window
        ]))->assertRedirect();

        $this->assertSame(1, \App\Models\ProxySession::where('booking_id', $booking->id)->open()->count());
    }

    public function test_booking_page_shows_status_controls_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Update status')
            ->assertSee('On Board (POB)')
            ->assertSee('Completed');
    }

    public function test_admin_can_change_status_from_the_booking_page(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        // The booking-page control posts to the same admin-override route the
        // dispatch board uses — reaching any stage directly (e.g. Arrived).
        $this->actingAs($admin)->post(route('despatch.quick-status', $booking), ['status' => 'arrived'])
            ->assertRedirect();

        $this->assertEquals(\App\Enums\BookingStatus::Arrived, $booking->fresh()->status);
    }

    public function test_admin_can_cancel_a_booking_with_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [
            'cancellation_reason' => 'Customer no longer needs the car',
        ])->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();
        $this->assertEquals(\App\Enums\BookingStatus::Cancelled, $booking->status);
        $this->assertEquals('Customer no longer needs the car', $booking->meta['cancellation_reason']);
        // Transition recorded in the audit history.
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'cancelled',
        ]);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [])
            ->assertSessionHasErrors('cancellation_reason');

        $this->assertNotEquals(\App\Enums\BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_driver_cannot_edit_or_cancel_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('bookings.edit', $booking))->assertForbidden();
        $this->actingAs($driver)->post(route('bookings.cancel', $booking), [
            'cancellation_reason' => 'nope',
        ])->assertForbidden();
    }

    public function test_it_flags_a_possible_duplicate_journey(): void
    {
        $vt = VehicleType::where('slug', 'executive')->first();
        $cust = \App\Models\Customer::create(['name' => 'Sienna Stancliffe-Clayton', 'phone' => '07700900001']);
        $make = fn () => Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $cust->id,
            'vehicle_type_id' => $vt->id, 'pickup_at' => '2026-07-20 16:00:00',
            'pickup_address' => 'Manchester Airport', 'destination_address' => '111 Fishponds Road West',
            'passengers' => 3, 'status' => 'pending', 'payment_method' => 'card',
        ]);

        $a = $make();
        $b = $make();

        $this->assertTrue($a->fresh()->looksDuplicated());
        $this->assertTrue($b->fresh()->duplicateCandidates()->contains('id', $a->id));

        // A cancelled twin doesn't count, and a different journey isn't flagged.
        $b->forceFill(['status' => 'cancelled'])->save();
        $this->assertFalse($a->fresh()->looksDuplicated());
    }
}
