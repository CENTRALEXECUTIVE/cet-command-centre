<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Per-booking "Resync from ETO email" — the email equivalent of Match calendar.
 * A forced resync takes the ETO email as the truth, overriding (and clearing) the
 * office-edit pins that otherwise stop a booking picking up an amendment.
 */
class BookingResyncEmailTest extends TestCase
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
            'is_booking' => true, 'cancelled' => false, 'reference' => 'PIN01',
            'customer_name' => 'James Watson', 'customer_phone' => '07700900123',
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2',
            'destination_address' => 'Radisson Blu Hotel, Sheffield',
            'pickup_at' => now()->addDays(4)->setTime(9, 0)->format('Y-m-d H:i'),
            'passengers' => 2, 'vehicle_type' => 'Executive', 'payment_status' => 'paid',
        ], $overrides);
    }

    public function test_a_forced_resync_overrides_an_office_pinned_field(): void
    {
        $svc = app(OutlookBookingService::class);
        $svc->upsertFromParsed($this->parsed());

        // Office pins the pickup time (and that's what normally blocks ETO updates).
        $booking = Booking::where('external_reference', 'PIN01')->first();
        $booking->forceFill([
            'pickup_at' => now()->addDays(4)->setTime(18, 30),
            'meta' => array_merge($booking->meta ?? [], ['manually_edited_at' => now()->toIso8601String(), 'edited_fields' => ['pickup_at']]),
        ])->save();

        // A NORMAL re-ingest respects the pin — time stays 18:30.
        $svc->upsertFromParsed($this->parsed(['pickup_at' => now()->addDays(4)->setTime(10, 0)->format('Y-m-d H:i')]));
        $this->assertSame('18:30', $booking->fresh()->pickup_at->format('H:i'));

        // A FORCED resync takes ETO as the truth — time becomes 11:00 and the pin clears.
        $svc->upsertFromParsed($this->parsed(['pickup_at' => now()->addDays(4)->setTime(11, 0)->format('Y-m-d H:i')]), allocateRotation: false, force: true);
        $fresh = $booking->fresh();
        $this->assertSame('11:00', $fresh->pickup_at->format('H:i'));
        $this->assertArrayNotHasKey('edited_fields', $fresh->meta ?? []);
        $this->assertArrayNotHasKey('manually_edited_at', $fresh->meta ?? []);
    }

    public function test_the_booking_page_offers_resync_for_an_eto_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['source_system' => 'eto', 'external_reference' => 'ABC123']);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Resync from ETO email', false);
    }

    public function test_resync_endpoint_reports_when_no_email_is_found(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['source_system' => 'eto', 'external_reference' => 'ABC123']);

        $outlook = Mockery::mock(OutlookBookingService::class);
        $outlook->shouldReceive('resyncBooking')->once()->andReturn(['status' => 'no_email']);
        $this->app->instance(OutlookBookingService::class, $outlook);

        $this->actingAs($admin)->post(route('bookings.resync-email', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'No ETO email found'));
    }

    public function test_resync_endpoint_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = Booking::factory()->create(['source_system' => 'eto', 'external_reference' => 'ABC123']);

        $this->actingAs($driver)->post(route('bookings.resync-email', $booking))->assertForbidden();
    }
}
