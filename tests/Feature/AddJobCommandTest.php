<?php

namespace Tests\Feature;

use App\Models\Booking;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddJobCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function args(array $overrides = []): array
    {
        return array_merge([
            '--ref' => 'H7424',
            '--name' => 'Maria',
            '--phone' => '+447443240744',
            '--at' => '2026-09-23 18:00',
            '--from' => 'AMRC, Advanced Manufacturing Park, Wallis Way, Catcliffe, Rotherham, S60 5TZ',
            '--to' => 'Sheffield Train Station',
            '--vehicle' => 'Executive',
            '--by' => 'Birmingham Corporate Travel Ltd',
        ], $overrides);
    }

    public function test_it_stores_the_booking_with_a_calendar_mirror(): void
    {
        $this->artisan('cet:add-job', $this->args())->assertSuccessful();

        $booking = Booking::where('external_reference', 'H7424')->first();
        $this->assertNotNull($booking);
        $this->assertSame('Maria', $booking->meta['lead_name']);
        $this->assertSame('Birmingham Corporate Travel Ltd', $booking->meta['booked_by']);
        $this->assertSame('18:00', $booking->pickup_at->format('H:i'));
        $this->assertSame('Sheffield Train Station', $booking->destination_address);
        $this->assertNotNull($booking->calendarEvent);
        $this->assertStringContainsString('Maria', $booking->calendarEvent->title);
    }

    public function test_running_twice_updates_not_duplicates(): void
    {
        $this->artisan('cet:add-job', $this->args())->assertSuccessful();
        $this->artisan('cet:add-job', $this->args(['--to' => 'Sheffield Midland Station']))->assertSuccessful();

        $this->assertSame(1, Booking::where('external_reference', 'H7424')->count());
        $this->assertSame('Sheffield Midland Station', Booking::where('external_reference', 'H7424')->first()->destination_address);
    }

    public function test_missing_required_field_fails_without_saving(): void
    {
        $this->artisan('cet:add-job', $this->args(['--from' => '']))->assertExitCode(2);
        $this->assertSame(0, Booking::count());
    }

    public function test_uk_local_time_is_not_shifted(): void
    {
        config(['app.timezone' => 'Europe/London']);
        $this->artisan('cet:add-job', $this->args(['--ref' => 'BST9', '--at' => '2026-07-10 06:45']))->assertSuccessful();
        $this->assertSame('06:45', Booking::where('external_reference', 'BST9')->first()->pickup_at->format('H:i'));
    }
}
