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
            // 08:00–23:00 send window regardless of when the test runs.
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
