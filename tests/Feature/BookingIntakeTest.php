<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\BookingIntakeService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'lead_name' => 'Jo Smith',
            'contact_no' => '07700 900123',
            'email' => '',
            'pickup_at' => '2026-07-12 14:30',
            'pickup_address' => '21 Ecclesall Rd, Sheffield',
            'destination_address' => 'Manchester Airport T2',
            'where' => 'MAN',
            'flight_number' => 'BA123',
            'passengers' => 2,
            'luggage' => 2,
            'vehicle' => 'Executive',
            'payment' => 'cash',
            'paid' => false,
            'booked_by' => 'Kerry',
            'notes' => '',
        ], $overrides);
    }

    public function test_preview_builds_cet_title_without_saving(): void
    {
        $preview = app(BookingIntakeService::class)->preview($this->fields());

        // *[emoji ]Name WHERE (TAG)* — cash unpaid → 💰, lead name Jo, where MAN.
        $this->assertStringContainsString('Jo', $preview['title']);
        $this->assertStringContainsString('MAN', $preview['title']);
        $this->assertStringStartsWith('*', $preview['title']);
        $this->assertStringContainsString('21 Ecclesall Rd', (string) $preview['location']);
        $this->assertStringContainsString('Booking Confirmation', $preview['description']);

        // Nothing persisted.
        $this->assertSame(0, Booking::count());
    }

    public function test_confirm_creates_booking_and_calendar_event(): void
    {
        $booking = app(BookingIntakeService::class)->confirm($this->fields(), null);

        $this->assertNotNull($booking->id);
        $this->assertSame('Jo Smith', $booking->fresh()->meta['lead_name']);
        $this->assertSame('14:30', $booking->pickup_at->format('H:i'));
        $this->assertSame('MAN', $booking->meta['where']);

        $event = CalendarEvent::where('booking_id', $booking->id)->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('Jo', $event->title);
        $this->assertSame('21 Ecclesall Rd, Sheffield', $event->location);
    }

    public function test_pickup_time_is_kept_as_uk_local(): void
    {
        // App tz is UTC in tests; a UK-local "14:30" must stay 14:30, not shift.
        $booking = app(BookingIntakeService::class)->confirm($this->fields(['pickup_at' => '2026-07-12 14:30']), null);
        $this->assertSame('14:30', $booking->pickup_at->format('H:i'));

        // The date-time picker submits an ISO "T" value — it must parse the same.
        $picker = app(BookingIntakeService::class)->confirm($this->fields(['pickup_at' => '2026-07-12T14:30']), null);
        $this->assertSame('14:30', $picker->pickup_at->format('H:i'));
    }

    public function test_admin_can_preview_and_confirm_through_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('intake.preview'), ['fields' => $this->fields()])
            ->assertOk()->assertSee('Calendar preview')->assertSee('Confirm');

        $this->actingAs($admin)->post(route('intake.confirm'), ['fields' => $this->fields()])
            ->assertRedirect();

        $this->assertSame(1, Booking::count());
    }

    public function test_confirm_requires_the_essentials(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('intake.confirm'), ['fields' => $this->fields(['lead_name' => ''])])
            ->assertSessionHasErrors('fields.lead_name');

        $this->assertSame(0, Booking::count());
    }

    public function test_non_admin_cannot_use_intake(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('intake.index'))->assertForbidden();
    }
}
