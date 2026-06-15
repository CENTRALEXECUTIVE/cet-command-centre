<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Flight\FlightMonitorService;
use App\Services\Flight\FlightStatusClient;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class FlightMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function flightBooking(): Booking
    {
        $customer = Customer::factory()->create(['phone' => '07700900500']);

        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id,
            'flight_number' => 'BA123',
            'pickup_at' => now()->addHours(3),
        ]);
    }

    private function fakeClient(int $delay, string $status = 'active'): void
    {
        $fake = Mockery::mock(FlightStatusClient::class);
        $fake->shouldReceive('arrival')->andReturn([
            'status' => $status,
            'scheduled_arrival' => Carbon::now()->addHours(3),
            'estimated_arrival' => Carbon::now()->addHours(3)->addMinutes($delay),
            'delay_minutes' => $delay,
        ]);
        $this->app->instance(FlightStatusClient::class, $fake);
    }

    public function test_delay_adjusts_pickup_and_notifies_customer(): void
    {
        $this->fakeClient(45);
        $booking = $this->flightBooking();
        $original = $booking->pickup_at->copy();

        $monitor = app(FlightMonitorService::class)->monitor($booking);

        $this->assertEquals(45, $monitor->delay_minutes);
        $this->assertTrue($monitor->pickup_adjusted);
        $this->assertEquals($original->copy()->addMinutes(45)->format('H:i'), $booking->fresh()->pickup_at->format('H:i'));
        $this->assertDatabaseHas('messages', ['to_address' => '07700900500', 'channel' => 'whatsapp']);
    }

    public function test_small_delay_does_not_adjust_pickup(): void
    {
        $this->fakeClient(5);
        $booking = $this->flightBooking();
        $original = $booking->pickup_at->copy();

        $monitor = app(FlightMonitorService::class)->monitor($booking);

        $this->assertFalse($monitor->pickup_adjusted);
        $this->assertEquals($original->format('H:i'), $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_no_monitor_without_flight_number(): void
    {
        $this->fakeClient(60);
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['flight_number' => null]);

        $this->assertNull(app(FlightMonitorService::class)->monitor($booking));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
