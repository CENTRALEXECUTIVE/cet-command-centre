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
        config([
            'cet.webhook_secret' => 'test-secret',
            'services.twilio_masking.customer_line' => '+441140000001',
            'services.twilio_masking.driver_line' => '+441140000002',
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'secret',
        ]);
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
            'pickup_at' => now()->addHour(),
        ]);
    }

    public function test_customer_is_connected_to_their_driver(): void
    {
        $this->activeJob();

        $this->assertEquals('+447700900222', app(MaskingService::class)->counterpartFor('07700900111'));
    }

    public function test_driver_is_connected_to_their_customer(): void
    {
        $this->activeJob();

        $this->assertEquals('+447700900111', app(MaskingService::class)->counterpartFor('07700900222'));
    }

    public function test_switchboard_routes_a_manually_entered_driver(): void
    {
        // The office usually sets the driver via the manual "Driver for this job"
        // form (no system user) — masking must still route to that number.
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id,
            'driver_id' => null,
            'status' => BookingStatus::EnRoute->value,
            'pickup_at' => now()->addHour(),
            'meta' => ['driver_details' => ['name' => 'Mehtab', 'phone' => '07395565934']],
        ]);

        $service = app(MaskingService::class);
        // Customer → the manually-entered driver.
        $this->assertSame('+447395565934', $service->resolve('07700900111')['dial']);
        // That driver → the customer.
        $this->assertSame('+447700900111', $service->resolve('07395565934')['dial']);
    }

    public function test_unknown_caller_has_no_counterpart(): void
    {
        $this->activeJob();

        $this->assertNull(app(MaskingService::class)->counterpartFor('07999999999'));
    }

    public function test_a_completed_job_no_longer_bridges_either_way(): void
    {
        // Once the job is marked Complete the line must go dead immediately, so a
        // driver can't text the customer after drop-off (post-trip contact goes
        // via the office). Regression: Completed jobs used to keep bridging for
        // up to 6h after pickup because only Cancelled/No-Show were excluded.
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $driver = User::factory()->driver()->create(['phone' => '07700900222']);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id, 'driver_id' => $driver->id,
            'status' => BookingStatus::Complete->value, 'pickup_at' => now()->subMinutes(30),
        ]);

        $service = app(MaskingService::class);
        $this->assertNull($service->resolve('07700900222')); // driver → nobody
        $this->assertNull($service->resolve('07700900111')); // customer → nobody

        // And an inbound text on a finished job is not forwarded.
        \Illuminate\Support\Facades\Http::fake();
        $this->post(route('webhooks.sms'), [
            'secret' => 'test-secret', 'From' => '07700900222', 'Body' => 'you left your bag',
        ])->assertOk()->assertSee('connect your message', false);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_voice_webhook_returns_dial_twiml(): void
    {
        $this->activeJob();

        $response = $this->post(route('webhooks.voice'), ['secret' => 'test-secret', 'From' => '07700900111']);
        $response->assertOk();
        $this->assertStringContainsString('<Dial', $response->getContent());
        $this->assertStringContainsString('+447700900222', $response->getContent());
    }

    public function test_voice_webhook_rejects_bad_secret(): void
    {
        $this->post(route('webhooks.voice'), ['secret' => 'wrong', 'From' => '07700900111'])->assertForbidden();
    }

    public function test_the_line_only_opens_ninety_minutes_before_pickup(): void
    {
        // The masked number is printed on the job sheet 24h ahead, but it must
        // NOT connect that early — only once pickup is within ~90 minutes.
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $driver = User::factory()->driver()->create(['phone' => '07700900222']);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id, 'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated->value, 'pickup_at' => now()->addHours(23),
        ]);

        $service = app(MaskingService::class);

        // 23h out → too early, the line is dead.
        $this->assertNull($service->counterpartFor('07700900111'));

        // 2h out → still too early.
        $booking->forceFill(['pickup_at' => now()->addHours(2)])->save();
        $this->assertNull($service->counterpartFor('07700900111'));

        // 80 min out → inside the 90-min window, now it connects.
        $booking->forceFill(['pickup_at' => now()->addMinutes(80)])->save();
        $this->assertEquals('+447700900222', $service->counterpartFor('07700900111'));
    }

    public function test_an_early_call_plays_a_tailored_cet_message(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $driver = User::factory()->driver()->create(['phone' => '07700900222']);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id, 'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated->value, 'pickup_at' => now()->addHours(5),
        ]);

        $service = app(MaskingService::class);
        $resolved = $service->resolve('07700900111');

        // Recognised as a known party, but too early → office message, no bridge.
        $this->assertSame('too_early', $resolved['reason'] ?? null);
        $twiml = $service->dialTwiml($resolved);
        $this->assertStringNotContainsString('<Dial', $twiml);
        $this->assertStringContainsString('opens closer to your pickup time', $twiml);
    }

    public function test_office_can_shorten_the_connect_window_per_booking(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $driver = User::factory()->driver()->create(['phone' => '07700900222']);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id, 'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated->value, 'pickup_at' => now()->addHours(2),
        ]);

        $service = app(MaskingService::class);
        // Default 90-min window → 2h out is too early.
        $this->assertNull($service->counterpartFor('07700900111'));

        // Widen the lead to 3h for THIS booking → now it connects.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['masking_lead_minutes' => 180])])->save();
        $this->assertEquals('+447700900222', $service->counterpartFor('07700900111'));
    }

    public function test_callee_sees_the_counterpart_cet_line_not_the_real_number(): void
    {
        $this->activeJob();
        $service = app(MaskingService::class);

        // Customer calls → driver is dialled, and the caller-ID shown is the CET
        // DRIVER line (so the driver can call/text back on it).
        $forCustomer = $service->resolve('07700900111');
        $this->assertSame('+447700900222', $forCustomer['dial']);
        $this->assertSame('+441140000002', $forCustomer['caller_id']);

        // Driver calls → customer is dialled, caller-ID is the CET CUSTOMER line.
        $forDriver = $service->resolve('07700900222');
        $this->assertSame('+447700900111', $forDriver['dial']);
        $this->assertSame('+441140000001', $forDriver['caller_id']);
    }

    public function test_sms_webhook_forwards_the_text_to_the_counterpart(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'api.twilio.com/*' => \Illuminate\Support\Facades\Http::response(['sid' => 'SM_x']),
        ]);
        $this->activeJob();

        $this->post(route('webhooks.sms'), [
            'secret' => 'test-secret', 'From' => '07700900111', 'Body' => 'I am outside',
        ])->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), 'Messages.json')
                && $request['From'] === '+441140000002'   // from the CET driver line
                && $request['To'] === '+447700900222'      // to the driver
                && $request['Body'] === 'I am outside';
        });
    }

    public function test_sms_from_an_unknown_number_is_not_forwarded(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->activeJob();

        $this->post(route('webhooks.sms'), [
            'secret' => 'test-secret', 'From' => '07999999999', 'Body' => 'hello',
        ])->assertOk()->assertSee('connect your message', false);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
