<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Import\EtoAuditService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EtoAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function csv(string $rows): UploadedFile
    {
        $header = "Journey date;Reference number;Lead passenger name;Status;Total;Payments\n";

        return UploadedFile::fake()->createWithContent('eto.csv', $header.$rows);
    }

    public function test_clean_booking_on_calendar_passes(): void
    {
        $booking = Booking::factory()->create([
            'external_reference' => 'ZWR6MM',
            'pickup_at' => '2025-03-24 22:05:00',
            'final_price' => 200,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_1',
            'title' => '*Jo Manchester Airport (EXEC)*',
            'location' => 'Manchester Airport T3',
            'description' => '📑 Booking Confirmation…',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $report = app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;ZWR6MM;Jo;Completed;200.00;\"Paid\"\n")
        );

        $this->assertSame(1, $report['counts']['checked']);
        $this->assertSame(1, $report['counts']['ok']);
        $this->assertSame(0, $report['counts']['flagged']);
        $this->assertEmpty($booking->fresh()->meta['audit_issues']);
    }

    public function test_flags_missing_calendar_and_bad_title(): void
    {
        $booking = Booking::factory()->create([
            'external_reference' => 'BADTTL',
            'pickup_at' => '2025-03-24 22:05:00',
            'final_price' => 200,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_2',
            'title' => 'Jo Manchester Airport', // not CET format
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $report = app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;BADTTL;Jo;Completed;200.00;\"Paid\"\n")
        );

        $this->assertSame(1, $report['counts']['flagged']);
        $issues = $booking->fresh()->meta['audit_issues'];
        $this->assertContains('Calendar title not in CET format', $issues);
    }

    public function test_flags_pickup_time_drift(): void
    {
        $booking = Booking::factory()->create([
            'external_reference' => 'TIMEXX',
            'pickup_at' => '2025-03-24 21:05:00', // one hour off
            'final_price' => 200,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_3',
            'title' => '*Jo Manchester Airport (EXEC)*',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $report = app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;TIMEXX;Jo;Completed;200.00;\"Paid\"\n")
        );

        $this->assertSame(1, $report['counts']['flagged']);
        $this->assertStringContainsString('Pickup time differs', implode(' ', $booking->fresh()->meta['audit_issues']));
    }

    public function test_flags_location_dropped_off_the_calendar(): void
    {
        $booking = Booking::factory()->create([
            'external_reference' => 'NOLOCN',
            'pickup_at' => '2025-03-24 22:05:00',
            'final_price' => 200,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_4',
            'title' => '*Jo Manchester Airport (EXEC)*',
            'location' => '', // dropped off the event
            'description' => '📑 Booking Confirmation…',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $report = app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;NOLOCN;Jo;Completed;200.00;\"Paid\"\n")
        );

        $this->assertSame(1, $report['counts']['flagged']);
        $this->assertStringContainsString('Pickup location has dropped off', implode(' ', $booking->fresh()->meta['audit_issues']));
    }

    public function test_reference_not_in_system_is_missing(): void
    {
        $report = app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;NOPE99;Jo;Completed;200.00;\"Paid\"\n")
        );

        $this->assertSame(1, $report['counts']['missing']);
        $this->assertSame('missing', $report['results'][0]['status']);
    }

    public function test_cancelled_booking_off_calendar_is_ok(): void
    {
        Booking::factory()->create([
            'external_reference' => 'CANC01',
            'status' => 'cancelled',
            'pickup_at' => '2025-03-24 22:05:00',
        ]);

        $report = app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;CANC01;Jo;Cancelled;0.00;\n")
        );

        $this->assertSame(1, $report['counts']['ok']);
    }

    public function test_admin_can_run_audit_through_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::factory()->create(['external_reference' => 'ZWR6MM', 'pickup_at' => '2025-03-24 22:05:00']);

        $this->actingAs($admin)
            ->post(route('audit.run'), ['file' => $this->csv("24/03/2025 22:05;ZWR6MM;Jo;Completed;200.00;\"Paid\"\n")])
            ->assertOk()
            ->assertSee('Flagged');
    }

    public function test_search_by_reference_reconfirms_a_booking(): void
    {
        $booking = Booking::factory()->create([
            'external_reference' => 'FINDME',
            'pickup_at' => '2025-03-24 22:05:00',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_s',
            'title' => '*Jo Manchester Airport (EXEC)*',
            'location' => '', // dropped — should be caught even without a CSV
            'description' => '📑 Booking Confirmation…',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $results = app(EtoAuditService::class)->search('FINDME');

        $this->assertCount(1, $results);
        $this->assertSame('flagged', $results[0]['status']);
        $this->assertStringContainsString('Pickup location has dropped off', implode(' ', $results[0]['issues']));
    }

    public function test_search_by_customer_name(): void
    {
        $customer = \App\Models\Customer::factory()->create(['name' => 'Barbara Windsor']);
        Booking::factory()->create([
            'customer_id' => $customer->id,
            'external_reference' => 'NAME01',
            'pickup_at' => '2025-03-24 22:05:00',
        ]);

        $results = app(EtoAuditService::class)->search('Barbara');

        $this->assertCount(1, $results);
        $this->assertSame('NAME01', $results[0]['reference']);
    }

    public function test_search_page_shows_matches(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::factory()->create(['external_reference' => 'PAGE01', 'pickup_at' => '2025-03-24 22:05:00']);

        $this->actingAs($admin)->get(route('audit.index', ['q' => 'PAGE01']))
            ->assertOk()->assertSee('PAGE01');
    }

    public function test_non_admin_cannot_open_audit(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('audit.index'))->assertForbidden();
    }

    /** Write the CSV to a temp file and return the path (service reads a path, not an upload). */
    private function csvPath(string $rows): string
    {
        $header = "Journey date;Reference number;Lead passenger name;Status;Total;Payments\n";
        $path = tempnam(sys_get_temp_dir(), 'eto').'.csv';
        file_put_contents($path, $header.$rows);

        return $path;
    }
}
