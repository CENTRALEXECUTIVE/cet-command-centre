<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Ai\AnthropicService;
use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
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
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    public function test_unconfigured_graph_is_a_no_op(): void
    {
        // No MS Graph credentials → fetchUnread returns [].
        $stats = app(OutlookBookingService::class)->ingest();

        $this->assertEquals(['processed' => 0, 'created' => 0, 'skipped' => 0], $stats);
        $this->assertEquals(0, Booking::count());
    }

    public function test_email_is_parsed_and_booking_created(): void
    {
        // Stub the mail client to return one unread email…
        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('fetchUnread')->andReturn([[
            'id' => 'msg-1', 'subject' => 'Airport pickup', 'from' => 'james@example.com',
            'body' => 'Please collect James Watson from Manchester Airport to Sheffield tomorrow 2pm, 2 passengers.',
        ]]);
        $mail->shouldReceive('markRead')->with('msg-1')->once();
        $this->app->instance(GraphMailClient::class, $mail);

        // …and the AI to return the extracted fields.
        $ai = Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('completeJson')->andReturn([
            'is_booking' => true, 'customer_name' => 'James Watson', 'customer_email' => 'james@example.com',
            'customer_phone' => '07700900123', 'pickup_address' => 'Manchester Airport',
            'destination_address' => 'Sheffield', 'pickup_at' => now()->addDay()->format('Y-m-d 14:00'),
            'passengers' => 2, 'vehicle_type' => 'Executive', 'flight_number' => 'BA123',
        ]);
        $this->app->instance(AnthropicService::class, $ai);

        $stats = app(OutlookBookingService::class)->ingest();

        $this->assertEquals(1, $stats['created']);
        $this->assertDatabaseHas('customers', ['name' => 'James Watson', 'phone' => '07700900123']);
        $booking = Booking::first();
        $this->assertEquals('Manchester Airport', $booking->pickup_address);
        $this->assertEquals('BA123', $booking->flight_number);
        $this->assertEquals(2, $booking->passengers);
    }

    public function test_non_booking_email_is_skipped(): void
    {
        $ai = Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('completeJson')->andReturn(['is_booking' => false]);
        $this->app->instance(AnthropicService::class, $ai);

        $parsed = app(OutlookBookingService::class)->parse('Re: invoice query', 'Just a question about my bill.', 'x@y.com');

        $this->assertNull($parsed);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
