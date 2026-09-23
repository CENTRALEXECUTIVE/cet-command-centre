<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\Telephony\MaskingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shared / multi-pickup jobs: the masked line FOLLOWS the journey. The party
 * being collected is live; once they're aboard their number drops and the next
 * pickup's goes live. Real numbers are admin-only — the driver only ever gets
 * the masked line for whoever is currently live.
 */
class SharedPickupMaskingTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 08:00:00');
        config([
            'services.twilio_masking.customer_line' => '+441111111111',
            'services.twilio_masking.driver_line' => '+442222222222',
        ]);
        // A cover (non-admin) driver, so masking is on and no real number shows.
        $this->driver = User::factory()->driver()->create(['phone' => '07111222333']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sharedJob(): Booking
    {
        $lead = Customer::create(['name' => 'Margaret Moran', 'phone' => '07544616024']);

        return Booking::factory()->create([
            'customer_id' => $lead->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addMinutes(20), // inside the masking window
            'pickup_address' => '3 Avill Way, Rotherham, S66 1DL',
            'destination_address' => 'Cruise Terminal One, Dover, CT17 9DQ',
            'meta' => [
                'stops' => [['address' => '47 Lockwood Avenue, South Anston, S25 5GQ']],
                // ADMIN-ONLY second-party contact — never shown to the driver.
                'stop_contacts' => [['name' => 'Barbara Horsfield', 'phone' => '07986942673']],
            ],
        ]);
    }

    public function test_the_current_party_advances_as_each_pickup_is_collected(): void
    {
        $b = $this->sharedJob();

        $this->assertTrue($b->hasMultiplePickupParties());
        $this->assertCount(2, $b->pickupParties());

        // Leg 1 — heading to the lead pickup.
        $this->assertSame(0, $b->activePickupIndex());
        $this->assertStringEndsWith('7544616024', $b->currentCustomerContactNumber());
        $this->assertSame('Margaret Moran', $b->currentPickupName());

        // Lead aboard → the next pickup is now live.
        $b->update(['status' => BookingStatus::Collected]);
        $b->refresh();
        $this->assertSame(1, $b->activePickupIndex());
        $this->assertStringEndsWith('7986942673', $b->currentCustomerContactNumber());
        $this->assertSame('Barbara Horsfield', $b->currentPickupName());
        $this->assertFalse($b->pickupsAllCollected());

        // Second party aboard → everyone collected.
        $b->markStopPickedUp(0);
        $b->refresh();
        $this->assertTrue($b->pickupsAllCollected());
    }

    public function test_a_single_customer_job_is_unchanged(): void
    {
        $solo = Customer::create(['name' => 'Solo', 'phone' => '07000000000']);
        $b = Booking::factory()->create([
            'customer_id' => $solo->id,
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addMinutes(20),
        ]);

        $this->assertFalse($b->hasMultiplePickupParties());
        $this->assertSame($b->customerContactNumber(), $b->currentCustomerContactNumber());
    }

    public function test_the_switchboard_bridges_the_driver_to_the_live_party_only(): void
    {
        $b = $this->sharedJob();
        $masking = app(MaskingService::class);

        // Driver rings the line → reaches the LEAD (leg 1).
        $asDriver = $masking->resolve('07111222333');
        $this->assertSame('customer', $asDriver['to']);
        $this->assertStringEndsWith('7544616024', $asDriver['dial']);

        // The lead can reach the driver; the not-yet-live second party cannot.
        $this->assertSame('driver', $masking->resolve('07544616024')['to']);
        $this->assertTrue($masking->resolve('07986942673')['office'] ?? false);
        $this->assertSame('not_live', $masking->resolve('07986942673')['reason']);

        // Lead aboard → the line now follows to the second party.
        $b->update(['status' => BookingStatus::Collected]);

        $this->assertStringEndsWith('7986942673', $masking->resolve('07111222333')['dial']);
        $this->assertSame('driver', $masking->resolve('07986942673')['to']);
        // The collected lead is no longer bridged mid-journey.
        $this->assertSame('not_live', $masking->resolve('07544616024')['reason']);
    }
}
