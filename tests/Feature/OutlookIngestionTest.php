<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Services\Ai\AnthropicService;
use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OutlookIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    private function parsed(array $overrides = []): array
    {
        return array_merge([
            'is_booking' => true, 'cancelled' => false, 'reference' => 'ZWR6MM',
            'customer_name' => 'James Watson', 'customer_email' => 'james@example.com',
            'customer_phone' => '07700900123', 'pickup_address' => 'Manchester Airport (MAN), Terminal 3',
            'destination_address' => 'Radisson Blu Hotel, Sheffield', 'pickup_at' => '2026-07-01 14:00',
            'passengers' => 2, 'vehicle_type' => 'Executive', 'flight_number' => 'BA123',
        ], $overrides);
    }

    public function test_unconfigured_graph_is_a_no_op(): void
    {
        $stats = app(OutlookBookingService::class)->ingest();
        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, Booking::count());
    }

    public function test_first_email_creates_booking_and_calendar_event_in_uk_time(): void
    {
        $result = app(OutlookBookingService::class)->upsertFromParsed($this->parsed());

        $this->assertEquals('created', $result['action']);
        $booking = Booking::where('external_reference', 'ZWR6MM')->first();
        $this->assertNotNull($booking);
        $this->assertEquals('eto', $booking->source_system);
        $this->assertEquals('MAN', $booking->airport->code);

        $event = CalendarEvent::where('booking_id', $booking->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Europe/London', $event->timezone);
        // 14:00 UK pickup → 15:00 UK end (+1h), in the format *👀 James Watson MAN (ABDI/EXECUTIVE)*
        $this->assertEquals('14:00', $event->start_at->format('H:i'));
        $this->assertEquals('15:00', $event->end_at->format('H:i'));
        $this->assertStringContainsString('James Watson', $event->title);
        $this->assertStringContainsString('MAN', $event->title);
    }

    public function test_same_reference_updates_not_duplicates(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        // Same reference, changed time + destination.
        $result = $svc->upsertFromParsed($this->parsed([
            'pickup_at' => '2026-07-01 16:30',
            'destination_address' => 'The Leopold Hotel, Sheffield',
        ]));

        $this->assertEquals('updated', $result['action']);
        $this->assertEquals(1, Booking::where('external_reference', 'ZWR6MM')->count());

        $booking = Booking::where('external_reference', 'ZWR6MM')->first();
        $this->assertStringContainsString('Leopold', $booking->destination_address);
        $this->assertEquals('16:30', $booking->pickup_at->format('H:i'));
        // Calendar event moved too (still one event for the booking).
        $this->assertEquals(1, CalendarEvent::where('booking_id', $booking->id)->count());
        $this->assertEquals('16:30', $booking->calendarEvent->start_at->format('H:i'));
    }

    public function test_cancellation_email_cancels_the_booking(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        $result = $svc->upsertFromParsed(['is_booking' => true, 'cancelled' => true, 'reference' => 'ZWR6MM']);

        $this->assertEquals('cancelled', $result['action']);
        $this->assertEquals(BookingStatus::Cancelled, Booking::where('external_reference', 'ZWR6MM')->first()->status);
    }

    public function test_full_pipeline_creates_via_mocked_email_and_ai(): void
    {
        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('fetchUnread')->andReturn([[
            'id' => 'm1', 'subject' => 'New booking ZWR6MM', 'from' => 'eto@easytaxioffice.co.uk',
            'body' => 'Booking reference ZWR6MM, James Watson, Manchester Airport to Sheffield 01/07/2026 14:00',
        ]]);
        $mail->shouldReceive('markRead')->with('m1')->once();
        $this->app->instance(GraphMailClient::class, $mail);

        $ai = Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('completeJson')->andReturn($this->parsed());
        $this->app->instance(AnthropicService::class, $ai);

        $stats = app(OutlookBookingService::class)->ingest();

        $this->assertEquals(1, $stats['created']);
        $this->assertEquals(1, Booking::where('external_reference', 'ZWR6MM')->count());
    }

    public function test_non_booking_email_is_skipped(): void
    {
        $ai = Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('completeJson')->andReturn(['is_booking' => false]);
        $this->app->instance(AnthropicService::class, $ai);

        $this->assertNull(app(OutlookBookingService::class)->parse('Re: invoice', 'A question', 'x@y.com'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
