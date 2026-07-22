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

    public function test_the_suggested_driver_tag_appears_in_the_title(): void
    {
        // The calendar is the origin, so the copied block carries the (TAG) —
        // fed in via meta['driver_tag'] so the operator pastes the right person.
        $preview = app(BookingIntakeService::class)->preview($this->fields(['driver_tag' => 'ABDI']));

        $this->assertStringContainsString('(ABDI)', $preview['title']);
    }

    public function test_pickup_time_is_kept_as_uk_local(): void
    {
        // App tz is UTC in tests; a UK-local "14:30" must stay 14:30, not shift.
        $draft = app(BookingIntakeService::class)->draft($this->fields(['pickup_at' => '2026-07-12 14:30']));
        $this->assertSame('14:30', $draft->pickup_at->format('H:i'));

        // The date-time picker submits an ISO "T" value — it must parse the same.
        $picker = app(BookingIntakeService::class)->draft($this->fields(['pickup_at' => '2026-07-12T14:30']));
        $this->assertSame('14:30', $picker->pickup_at->format('H:i'));
    }

    public function test_preview_page_shows_the_copy_block_and_creates_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('intake.preview'), ['fields' => $this->fields()])
            ->assertOk()
            ->assertSee('Copy onto the calendar')
            ->assertSee('Jo');

        // The tool never persists a booking — the calendar is the single origin.
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_there_is_no_confirm_route(): void
    {
        // The create path is gone: intake can only format for the calendar.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('intake.confirm'));
    }

    public function test_non_admin_cannot_use_intake(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('intake.index'))->assertForbidden();
    }
}
