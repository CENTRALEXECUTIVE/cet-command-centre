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
            'pickup_at' => now()->addDays(2)->format('Y-m-d H:i'),
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

    public function test_due_messages_command_delivers_queued_reminders(): void
    {
        $booking = $this->makeBooking();

        // Force the reminders due.
        Message::where('booking_id', $booking->id)
            ->where('status', 'queued')
            ->update(['scheduled_for' => now()->subMinute()]);

        $this->artisan('cet:send-due-messages')->assertSuccessful();

        $this->assertEquals(
            0,
            Message::where('booking_id', $booking->id)->where('status', 'queued')->count()
        );
    }
}
