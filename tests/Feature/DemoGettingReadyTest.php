<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `cet:demo-getting-ready` spins up a throwaway job assigned to a director with
 * an imminent pickup, so the "Getting ready" checkpoint button is visible right
 * away for a look. Staging only.
 */
class DemoGettingReadyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
        VehicleType::create(['name' => 'Executive', 'slug' => 'executive', 'passenger_capacity' => 4, 'affects_rotation' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_creates_a_job_with_the_button_showing_now(): void
    {
        $abdi = User::factory()->admin()->create(['email' => 'abdi@cet.test']);

        $this->artisan('cet:demo-getting-ready', ['email' => 'abdi@cet.test', '--minutes' => 25])
            ->assertSuccessful();

        $booking = Booking::where('driver_id', $abdi->id)->firstOrFail();
        $this->assertSame(BookingStatus::Allocated, $booking->status);
        $this->assertSame(25, (int) round(now()->diffInMinutes($booking->pickup_at, false)));

        // The checkpoint is un-confirmed and its prompt window is open right now,
        // so the button renders immediately.
        $this->assertFalse($booking->gettingReadyConfirmed());
        $this->assertTrue(now()->gte($booking->gettingReadyPromptAt()));
    }

    public function test_it_errors_on_an_unknown_email(): void
    {
        $this->artisan('cet:demo-getting-ready', ['email' => 'nobody@cet.test'])
            ->assertFailed();

        $this->assertSame(0, Booking::count());
    }

    public function test_the_demo_job_is_removed_by_remove_demo(): void
    {
        $abdi = User::factory()->admin()->create(['email' => 'abdi@cet.test']);
        $this->artisan('cet:demo-getting-ready', ['email' => 'abdi@cet.test'])->assertSuccessful();

        $this->assertSame(1, Booking::count());
        $this->artisan('cet:remove-demo')->assertSuccessful();
        $this->assertSame(0, Booking::count());
    }
}
