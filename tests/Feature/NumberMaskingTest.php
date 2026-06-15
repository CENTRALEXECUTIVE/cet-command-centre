<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Telephony\MaskingService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.webhook_secret' => 'test-secret']);
    }

    private function activeJob(): Booking
    {
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $driver = User::factory()->driver()->create(['phone' => '07700900222']);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id,
            'driver_id' => $driver->id,
            'status' => BookingStatus::EnRoute->value,
        ]);
    }

    public function test_customer_is_connected_to_their_driver(): void
    {
        $this->activeJob();

        $this->assertEquals('07700900222', app(MaskingService::class)->counterpartFor('07700900111'));
    }

    public function test_driver_is_connected_to_their_customer(): void
    {
        $this->activeJob();

        $this->assertEquals('07700900111', app(MaskingService::class)->counterpartFor('07700900222'));
    }

    public function test_unknown_caller_has_no_counterpart(): void
    {
        $this->activeJob();

        $this->assertNull(app(MaskingService::class)->counterpartFor('07999999999'));
    }

    public function test_voice_webhook_returns_dial_twiml(): void
    {
        $this->activeJob();

        $response = $this->post(route('webhooks.voice'), ['secret' => 'test-secret', 'From' => '07700900111']);
        $response->assertOk();
        $this->assertStringContainsString('<Dial', $response->getContent());
        $this->assertStringContainsString('07700900222', $response->getContent());
    }

    public function test_voice_webhook_rejects_bad_secret(): void
    {
        $this->post(route('webhooks.voice'), ['secret' => 'wrong', 'From' => '07700900111'])->assertForbidden();
    }
}
