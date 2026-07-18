<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    private function makeBooking(): Booking
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();

        return app(BookingService::class)->createFromForm([
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $executive->id,
            'journey_type' => 'one_way',
            // Pinned to 15:00 so the 24h/2h reminders always fall inside the
            // 08:00–22:00 send window regardless of when the test runs.
            'pickup_at' => now()->addDays(2)->setTime(15, 0)->format('Y-m-d H:i'),
            'pickup_address' => '12 Fargate, Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'payment_method' => 'card',
            'privacy_consent' => '1',
        ], $admin);
    }

    public function test_booking_sends_confirmation_and_schedules_reminders(): void
    {
        $booking = $this->makeBooking();

        // Immediate confirmation (log transport → marked sent).
        $confirmation = Message::where('booking_id', $booking->id)->where('type', 'confirmation')->first();
        $this->assertNotNull($confirmation);
        $this->assertEquals('sent', $confirmation->status);

        // 24h and 2h reminders queued for the future.
        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'reminder_24h', 'status' => 'queued']);
        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'reminder_2h', 'status' => 'queued']);

        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_2h')->first();
        $this->assertTrue($reminder->scheduled_for->isFuture());
    }

    public function test_due_reminders_stay_queued_for_manual_sending(): void
    {
        $booking = $this->makeBooking();

        // Force the reminders due.
        Message::where('booking_id', $booking->id)
            ->where('status', 'queued')
            ->update(['scheduled_for' => now()->subMinute()]);

        $this->artisan('cet:send-due-messages')->assertSuccessful();

        // Reminders are NOT auto-sent — the office sends them by hand, so they
        // remain queued as tasks.
        $this->assertGreaterThan(
            0,
            Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->where('status', 'queued')->count()
        );
    }

    public function test_reminder_has_a_prefilled_whatsapp_link_and_can_be_marked_sent(): void
    {
        $booking = $this->makeBooking();
        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->first();

        // wa.me link carries the customer's intl number and the encoded body.
        $link = $reminder->whatsAppLink();
        $this->assertStringStartsWith('https://wa.me/447700900123?text=', $link);
        $this->assertStringContainsString(rawurlencode('*Booking Reminder*'), $link);

        // Operator marks it sent after sending from their own WhatsApp.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('messages.sent', $reminder))->assertRedirect();
        $this->assertEquals('sent', $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->sent_at);
    }

    public function test_reminder_targets_the_bookings_own_contact_not_a_merged_customer_phone(): void
    {
        // The linked customer record carries an OLD/other phone (e.g. it got
        // matched to another booker by a shared email on import). The booking's
        // OWN contact — the calendar "Contact No" — is the real customer number.
        // The reminder must go to the booking's number, never the stale record.
        $booking = Booking::factory()->create(['pickup_at' => now()->addDay()->setTime(15, 0)]);
        $booking->customer->update(['phone' => '07588804226']); // wrong/merged number

        \App\Models\CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_contact',
            'title' => '*Darren Pearson MAN (MAJ)*',
            'location' => 'Manchester Airport',
            'description' => implode("\n", [
                '📑 *Booking Confirmation – Arrival*',
                '• *Customer Name:* Darren Pearson',
                '• *Contact No:* +447971871155', // the real number for THIS booking
                '• *Pickup Location:* Manchester Airport',
            ]),
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);
        $booking = $booking->fresh(['customer', 'calendarEvent']);

        app(\App\Services\Messaging\BookingNotifier::class)->ensureReminders($booking);

        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->first();
        $this->assertNotNull($reminder);
        // Queued against the booking's real contact, not the merged record's phone.
        $this->assertStringContainsString('447971871155', $reminder->whatsAppLink());
        $this->assertStringNotContainsString('7588804226', $reminder->whatsAppLink());
    }

    public function test_send_link_uses_the_bookings_current_number_even_if_the_message_was_queued_earlier(): void
    {
        // A reminder was queued to an old/wrong number (before the contact was
        // corrected). The "Send on WhatsApp" link must still go to the booking's
        // CURRENT contact, not the stale recipient frozen on the message.
        $booking = Booking::factory()->create(['pickup_at' => now()->addDay()->setTime(15, 0)]);
        $booking->customer->update(['phone' => '07971871155']);

        $message = Message::create([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'channel' => 'whatsapp',
            'direction' => 'outbound',
            'type' => 'reminder_24h',
            'to_address' => '07588804226', // stale/wrong number stored at queue time
            'body' => '*Booking Reminder*',
            'status' => 'queued',
        ]);

        $link = $message->whatsAppLink();
        $this->assertStringContainsString('447971871155', $link);   // the current number
        $this->assertStringNotContainsString('7588804226', $link);  // never the stale one
    }

    public function test_stale_link_host_in_a_queued_message_is_rewritten_to_the_current_app_host(): void
    {
        // A message queued with an old "staging." link must show/send on the
        // current app host — but the marketing site + Google links stay put.
        config(['app.url' => 'https://app.centralexecutivetransfers.co.uk']);
        $booking = Booking::factory()->create();

        $message = Message::create([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'review_request',
            'to_address' => '07700900123', 'status' => 'queued',
            'body' => "Review us: https://g.page/x/review\n"
                ."💛 Tip: https://staging.centralexecutivetransfers.co.uk/tip/abc123\n"
                .'Track: https://staging.centralexecutivetransfers.co.uk/track/xyz789'."\n"
                .'🌐 www.centralexecutivetransfers.co.uk',
        ]);

        $rendered = $message->renderedBody();
        $this->assertStringContainsString('https://app.centralexecutivetransfers.co.uk/tip/abc123', $rendered);
        $this->assertStringContainsString('https://app.centralexecutivetransfers.co.uk/track/xyz789', $rendered);
        $this->assertStringNotContainsString('staging.', $rendered);          // no staging anywhere
        $this->assertStringContainsString('https://g.page/x/review', $rendered); // external link untouched
        $this->assertStringContainsString('www.centralexecutivetransfers.co.uk', $rendered); // marketing link untouched
    }

    public function test_24h_reminder_is_shifted_into_the_daytime_window(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();

        // Pickup at 07:00 in a few days → 24h mark is 07:00 (before 08:00),
        // so the reminder must be shifted to 08:00 the same morning.
        $pickup = now()->addDays(5)->setTime(7, 0);
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => 'Early Bird', 'customer_phone' => '07700900555',
            'vehicle_type_id' => $executive->id, 'journey_type' => 'one_way',
            'pickup_at' => $pickup->format('Y-m-d H:i'),
            'pickup_address' => '12 Fargate, Sheffield', 'destination_address' => 'Manchester Airport',
            'passengers' => 1, 'payment_method' => 'card', 'privacy_consent' => '1',
        ], $admin);

        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->first();
        $this->assertNotNull($reminder);
        $this->assertEquals('08:00', $reminder->scheduled_for->format('H:i'));
        $this->assertEquals($pickup->copy()->subDay()->toDateString(), $reminder->scheduled_for->toDateString());
    }

    public function test_dashboard_backfills_reminders_so_tomorrows_jobs_show(): void
    {
        $admin = User::factory()->admin()->create();
        // An imported-style booking (never went through the form) for tomorrow.
        $booking = Booking::factory()->create(['pickup_at' => now()->addDay()->setTime(15, 0)]);
        $booking->customer->update(['phone' => '07700900321']);

        $this->assertDatabaseMissing('messages', ['booking_id' => $booking->id, 'type' => 'reminder_24h']);

        // Simply opening the dashboard prepares the reminder so it appears.
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'reminder_24h']);
    }

    public function test_late_night_reminder_is_pulled_back_to_the_ten_pm_edge(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();

        // Pickup at 23:30 → 24h mark is 23:30 (after 22:00), so the reminder is
        // pulled back to 22:00 the day before — never sent late at night.
        $pickup = now()->addDays(5)->setTime(23, 30);
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => 'Late Runner', 'customer_phone' => '07700900557',
            'vehicle_type_id' => $executive->id, 'journey_type' => 'one_way',
            'pickup_at' => $pickup->format('Y-m-d H:i'),
            'pickup_address' => '12 Fargate, Sheffield', 'destination_address' => 'Manchester Airport',
            'passengers' => 1, 'payment_method' => 'card', 'privacy_consent' => '1',
        ], $admin);

        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->first();
        $this->assertNotNull($reminder);
        $this->assertEquals('22:00', $reminder->scheduled_for->format('H:i'));
        $this->assertEquals($pickup->copy()->subDay()->toDateString(), $reminder->scheduled_for->toDateString());
    }

    public function test_reminder_is_not_offered_for_manual_send_until_its_window(): void
    {
        // Before its (windowed) send time a reminder must not show the manual
        // "Send on WhatsApp" prompt — the office isn't nudged to message early.
        $future = new Message(['type' => 'reminder_24h']);
        $future->scheduled_for = now()->addHours(5);
        $this->assertFalse($future->isReadyToSend());

        // Once the send time has arrived it becomes sendable.
        $due = new Message(['type' => 'reminder_24h']);
        $due->scheduled_for = now()->subMinute();
        $this->assertTrue($due->isReadyToSend());

        // Non-reminder messages (confirmation, driver details…) are always sendable.
        $this->assertTrue((new Message(['type' => 'confirmation']))->isReadyToSend());
    }

    public function test_2h_reminder_is_skipped_when_it_falls_overnight(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();

        // Pickup at 03:00 → 2h mark is 01:00, outside the window → no 2h nudge.
        $pickup = now()->addDays(5)->setTime(3, 0);
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => 'Night Owl', 'customer_phone' => '07700900556',
            'vehicle_type_id' => $executive->id, 'journey_type' => 'one_way',
            'pickup_at' => $pickup->format('Y-m-d H:i'),
            'pickup_address' => '12 Fargate, Sheffield', 'destination_address' => 'Manchester Airport',
            'passengers' => 1, 'payment_method' => 'card', 'privacy_consent' => '1',
        ], $admin);

        $this->assertDatabaseMissing('messages', ['booking_id' => $booking->id, 'type' => 'reminder_2h']);
        // The 24h reminder still exists (03:00 → 24h mark 03:00 → shifted to 08:00).
        $this->assertDatabaseHas('messages', ['booking_id' => $booking->id, 'type' => 'reminder_24h']);
    }

    public function test_late_booking_reminder_stays_on_the_list_until_it_is_sent(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 15:00:00');

        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();

        // A booking added at the last minute — pickup only 30 minutes away.
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => 'Late Booker', 'customer_phone' => '07700900999',
            'vehicle_type_id' => $executive->id, 'journey_type' => 'one_way',
            'pickup_at' => now()->addMinutes(30)->format('Y-m-d H:i'),
            'pickup_address' => '1 Test St, Sheffield', 'destination_address' => 'Leeds Bradford Airport',
            'passengers' => 1, 'payment_method' => 'card', 'privacy_consent' => '1',
        ], $admin);

        // A due-now reminder is queued for the late job.
        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->first();
        $this->assertNotNull($reminder);
        $this->assertSame('queued', $reminder->status);

        $ref = $booking->external_reference ?? $booking->reference;
        $onList = fn () => collect($this->actingAs($admin)->get(route('dashboard'))->viewData('remindersToSend'))
            ->pluck('ref');

        // The pickup time passes without anyone having sent the reminder yet.
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 16:00:00');

        // It must STILL be on the "to send" list — a late job must not silently
        // drop off the moment its pickup time ticks past.
        $this->assertTrue($onList()->contains($ref), 'Unsent reminder should stay on the list after pickup passes.');

        // Marking it sent is the ONLY thing that removes it from the list.
        $reminder->update(['status' => 'sent']);
        $this->assertFalse($onList()->contains($ref), 'A sent reminder should leave the list.');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_allocating_a_driver_sends_the_driver_details(): void
    {
        $booking = $this->makeBooking();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Kash Khan', 'email' => 'kash@cet.test']);
        $vehicle = \App\Models\Vehicle::create([
            'vehicle_type_id' => $booking->vehicle_type_id,
            'registration' => 'AB12 CDE', 'make' => 'Mercedes', 'model' => 'E-Class', 'colour' => 'Black', 'is_active' => true,
        ]);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'default_vehicle_id' => $vehicle->id]);

        $admin = User::factory()->admin()->create();
        app(\App\Services\BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $msg = Message::where('booking_id', $booking->id)->where('type', 'driver_details')->first();
        $this->assertNotNull($msg);
        // Falls back to the email local-part when no explicit callsign is set.
        $this->assertStringContainsString('Driver Name: Kash', $msg->body);
        $this->assertStringContainsString('Vehicle Reg: AB12 CDE', $msg->body);
        $this->assertStringContainsString('BLACK MERCEDES E-CLASS', $msg->body);
        $this->assertStringContainsString('*Central Executive Transfers*', $msg->body);
    }

    public function test_reminder_uses_the_office_template_with_driver_details(): void
    {
        // Executive job → rotation allocates ABDI at creation, so the reminder
        // rendered at send time carries the driver block.
        $executive = VehicleType::where('slug', 'executive')->first();
        $admin = User::factory()->admin()->create();
        $pickup = now()->addDays(3)->setTime(13, 15);
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => 'Louise Taylor', 'customer_phone' => '07700900321',
            'vehicle_type_id' => $executive->id, 'journey_type' => 'one_way',
            'pickup_at' => $pickup->format('Y-m-d H:i'),
            'pickup_address' => '12 Fargate, Sheffield', 'destination_address' => 'Manchester Airport',
            'passengers' => 1, 'payment_method' => 'card', 'privacy_consent' => '1',
        ], $admin);

        // Force the queued 24h reminder due; the command re-renders its body
        // (with the current driver) but leaves it queued for manual sending.
        Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')
            ->update(['scheduled_for' => now()->subMinute()]);
        $this->artisan('cet:send-due-messages')->assertSuccessful();

        $reminder = Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->first();
        $this->assertStringContainsString('*Booking Reminder*', $reminder->body);
        $this->assertStringContainsString('Hi Louise,', $reminder->body);
        $this->assertStringContainsString('at *13:15*', $reminder->body);
        $this->assertStringContainsString('*Driver details*', $reminder->body);
        $this->assertStringContainsString('*Central Executive Transfers*', $reminder->body);
        $this->assertEquals('queued', $reminder->fresh()->status);
    }

    public function test_explicit_callsign_overrides_the_login_name(): void
    {
        $booking = $this->makeBooking();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Majid Ali', 'email' => 'majid.ali@cet.test']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'callsign' => 'Maj']);

        $admin = User::factory()->admin()->create();
        app(\App\Services\BookingStatusService::class)->allocateDriver($booking, $driver, $admin);

        $msg = Message::where('booking_id', $booking->id)->where('type', 'driver_details')->first();
        $this->assertStringContainsString('Driver Name: Maj', $msg->body);
        $this->assertStringNotContainsString('Majid', $msg->body);
    }

    public function test_imported_booking_gets_a_reminder_when_opened(): void
    {
        // Freeze at midday so the 24h reminder (due at 09:00 today) is already in
        // the past and offered for manual sending, whatever time the suite runs.
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 12:00:00');

        // A booking created directly (like an ETO import) — no reminders queued.
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = \App\Models\Customer::create(['name' => 'Philip Agerbech', 'phone' => '07700900444']);
        $booking = Booking::create([
            'reference' => Booking::generateReference(), 'source_system' => 'eto',
            'customer_id' => $customer->id, 'vehicle_type_id' => $executive->id,
            'pickup_at' => now()->addDays(1)->setTime(9, 0),
            'pickup_address' => 'Manchester Airport', 'destination_address' => 'Sheffield',
            'passengers' => 2, 'status' => 'pending', 'payment_method' => 'card',
        ]);

        $this->assertEquals(0, $booking->messages()->count());

        // Opening the booking backfills the reminder so it's ready to send.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Send on WhatsApp');

        $this->assertGreaterThan(0, $booking->messages()->where('type', 'reminder_24h')->count());

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_prepare_reminders_command_backfills_upcoming_bookings(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = \App\Models\Customer::create(['name' => 'Backfill Bob', 'phone' => '07700900777']);
        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id, 'vehicle_type_id' => $executive->id,
            'pickup_at' => now()->addDays(2)->setTime(10, 0),
            'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ]);

        $this->artisan('cet:prepare-reminders')->assertSuccessful();
        $this->assertGreaterThan(0, $booking->messages()->where('type', 'reminder_24h')->count());

        // Idempotent — running again doesn't duplicate.
        $count = $booking->messages()->count();
        $this->artisan('cet:prepare-reminders')->assertSuccessful();
        $this->assertEquals($count, $booking->fresh()->messages()->count());
    }

    public function test_completed_job_without_a_review_gets_one_backfilled(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 12:00:00');

        // A job completed a couple of hours ago that never went through the live
        // Complete-tap (e.g. an ETO import marked done) — no review request.
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = \App\Models\Customer::create(['name' => 'Lewis Reviewer', 'phone' => '07700900888']);
        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id, 'vehicle_type_id' => $executive->id,
            'pickup_at' => now()->subHours(2),
            'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'complete', 'payment_method' => 'card',
        ]);
        $this->assertEquals(0, $booking->messages()->where('type', 'review_request')->count());

        // Opening the dashboard backfills the review request.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertEquals(1, $booking->fresh()->messages()->where('type', 'review_request')->count());

        // The scheduled command is idempotent — no duplicate.
        $this->artisan('cet:prepare-reminders')->assertSuccessful();
        $this->assertEquals(1, $booking->fresh()->messages()->where('type', 'review_request')->count());

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_reminder_greets_the_lead_passenger_not_the_booker(): void
    {
        $booking = $this->makeBooking(); // customer = James Watson
        $booking->calendarEvent()->delete(); // no calendar event → meta is the source
        // A PA booked it for a different lead passenger.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['lead_name' => 'Jo Brown'])])->save();

        $body = app(\App\Services\Messaging\BookingNotifier::class)->reminderBody($booking->fresh());
        $this->assertStringContainsString('Hi Jo,', $body);
        $this->assertStringNotContainsString('Hi James', $body);
    }

    public function test_reminder_greeting_falls_back_to_the_calendar_title(): void
    {
        $booking = $this->makeBooking(); // customer = James Watson, no lead_name
        // The calendar event names the lead passenger (booked by someone else).
        $booking->calendarEvent()->update(['title' => '*Jo Brown EMA (COVER)*']);

        $body = app(\App\Services\Messaging\BookingNotifier::class)->reminderBody($booking->fresh());
        $this->assertStringContainsString('Hi Jo,', $body);
        $this->assertStringNotContainsString('Hi James', $body);
    }

    public function test_manual_driver_details_go_into_the_reminder(): void
    {
        $booking = $this->makeBooking();
        $admin = User::factory()->admin()->create();

        // Operator enters a third-party driver for this job.
        $this->actingAs($admin)->post(route('bookings.driver-details', $booking), [
            'name' => 'Kash', 'phone' => '07785 729671',
            'reg' => 'am64 far', 'car' => 'Black Mercedes V Class',
        ])->assertRedirect();

        // The reminder now carries that driver block (reg uppercased).
        $body = app(\App\Services\Messaging\BookingNotifier::class)->reminderBody($booking->fresh());
        $this->assertStringContainsString('Driver Name: Kash', $body);
        $this->assertStringContainsString('Driver Contact Number: 07785 729671', $body);
        $this->assertStringContainsString('Vehicle Reg: AM64 FAR', $body);
        $this->assertStringContainsString('BLACK MERCEDES V CLASS', $body);
    }

    public function test_admin_can_send_a_custom_message(): void
    {
        $booking = $this->makeBooking();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.message', $booking), [
            'body' => 'Your driver will be 5 minutes early.',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'booking_id' => $booking->id,
            'type' => 'custom',
            'body' => 'Your driver will be 5 minutes early.',
        ]);
    }
}
