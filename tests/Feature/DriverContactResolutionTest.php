<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The driver link / brief / masking must reach the ALLOCATED driver's own number
 * and never silently fall back to a manually-typed driver_details number — that
 * fallback is how a job for one "Haseeb" could message a different "Haseeb".
 */
class DriverContactResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_allocated_drivers_own_number(): void
    {
        $driver = User::factory()->driver()->create(['phone' => '+447700900111']);
        $b = Booking::factory()->create([
            'driver_id' => $driver->id,
            'meta' => ['driver_details' => ['name' => 'Other Haseeb', 'phone' => '+447700900222']],
        ]);

        // The allocated driver's number wins — NOT the stale driver_details.
        $this->assertSame('+447700900111', $b->driverRealPhone());
        $this->assertStringContainsString('447700900111', (string) $b->driverWhatsAppLink());
        $this->assertStringNotContainsString('447700900222', (string) $b->driverWhatsAppLink());
    }

    public function test_it_does_not_fall_back_to_another_persons_number_when_the_driver_has_none(): void
    {
        // The allocated driver has NO phone saved. We must return null (so the UI
        // warns), NEVER the different person sitting in driver_details.
        $driver = User::factory()->driver()->create(['phone' => null]);
        $b = Booking::factory()->create([
            'driver_id' => $driver->id,
            'meta' => ['driver_details' => ['name' => 'Wrong Haseeb', 'phone' => '+447700900222']],
        ]);

        $this->assertNull($b->driverRealPhone());
        $this->assertNull($b->driverWhatsAppLink());
    }

    public function test_a_job_with_no_login_driver_uses_the_manual_details(): void
    {
        $b = Booking::factory()->create([
            'driver_id' => null,
            'meta' => ['driver_details' => ['name' => 'Cover Dave', 'phone' => '+447700900333']],
        ]);

        $this->assertSame('+447700900333', $b->driverRealPhone());
        $this->assertSame('Cover Dave', $b->driverContactLabel());
    }
}
