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
        // The fixtures below are dated 24/03/2025; freeze "now" a few days before
        // that so they count as UPCOMING jobs — the calendar-quality checks only
        // run for jobs still to happen (a past job's sync status is history).
        \Illuminate\Support\Carbon::setTestNow('2025-03-20 09:00:00');
        // Push the "old" cutoff well back so the 2025 fixtures are still audited;
        // the cutoff behaviour has its own dedicated test.
        config(['cet.audit_cutoff' => '2020-01-01']);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
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

    public function test_past_job_calendar_status_is_not_flagged_as_noise(): void
    {
        // A finished job from a week ago with a failed sync + no calendar event:
        // its calendar status is history, so the audit must NOT flag it — that
        // was the "260 flagged" noise the office couldn't action.
        $booking = Booking::factory()->create([
            'external_reference' => 'OLDJOB',
            'pickup_at' => now()->subDays(7)->setTime(14, 0),
            'status' => \App\Enums\BookingStatus::Complete,
            'final_price' => 120,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_old',
            'title' => 'Jo Manchester Airport', // not CET format — but it's history
            'sync_status' => 'failed',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
        ]);

        $report = app(EtoAuditService::class)->audit(
            $this->csvPath($booking->pickup_at->format('d/m/Y H:i').";OLDJOB;Jo;Completed;120.00;\"Paid\"\n")
        );

        $this->assertSame(0, $report['counts']['flagged']);
        $this->assertSame(1, $report['counts']['ok']);
    }

    public function test_bookings_before_the_cutoff_are_archived_not_audited(): void
    {
        // A booking before the cutoff with NO calendar event — normally a problem
        // for a live job, but it predates the live-calendar era, so it's archive.
        config(['cet.audit_cutoff' => '2025-03-22']);
        $booking = Booking::factory()->create([
            'external_reference' => 'OLDONE',
            'pickup_at' => '2025-03-21 09:00:00',
            'status' => \App\Enums\BookingStatus::Allocated,
        ]);

        $results = app(EtoAuditService::class)->search('OLDONE');

        $this->assertSame('old', $results[0]['status']);
        $this->assertSame([], $results[0]['issues']);
    }

    public function test_a_failed_sync_alone_is_not_flagged_when_the_event_is_on_the_calendar(): void
    {
        // The event has a google_event_id → it IS on the calendar. A stale
        // sync_status of "failed" must NOT raise a "not synced" error on its own.
        $booking = Booking::factory()->create([
            'external_reference' => 'SYNCF',
            'pickup_at' => '2025-03-24 09:00:00',
            'status' => \App\Enums\BookingStatus::Allocated,
            'final_price' => 100,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_sync',
            'title' => '*Jo EMA (ABDI)*',
            'location' => '1 Test Street, Sheffield',
            'description' => 'Booking Confirmation block present',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'failed',
        ]);

        $results = app(EtoAuditService::class)->search('SYNCF');

        $this->assertSame('ok', $results[0]['status'],
            'a failed sync flag alone must not flag a booking that is on the calendar');
    }

    public function test_audit_corrects_a_drifted_booking_time_to_the_calendar(): void
    {
        // The calendar takes priority — the audit should CORRECT the booking to
        // the calendar time (not just flag it) and leave no time warning behind.
        $booking = Booking::factory()->create([
            'external_reference' => 'TIMEXX',
            'pickup_at' => '2025-03-24 21:05:00', // an hour off the calendar
            'pickup_address' => '1 Test St, Sheffield',
            'destination_address' => 'Manchester Airport',
            'final_price' => 200,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_3',
            'title' => '*Jo Manchester Airport (EXEC)*',
            'location' => 'Manchester Airport',
            'description' => '📑 Booking Confirmation',
            'start_at' => '2025-03-24 22:05:00', // the calendar's time
            'end_at' => '2025-03-24 23:05:00',
            'sync_status' => 'synced',
        ]);

        app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;TIMEXX;Jo;Completed;200.00;\"Paid\"\n")
        );

        $booking = $booking->fresh();
        $this->assertEquals('22:05', $booking->pickup_at->format('H:i')); // corrected to the calendar
        $this->assertStringNotContainsString("doesn't match", implode(' ', $booking->meta['audit_issues'] ?? []));
    }

    public function test_eto_time_of_day_difference_alone_is_not_flagged(): void
    {
        // Calendar + command centre both 21:05; ETO says 22:05. Not an issue.
        $booking = Booking::factory()->create([
            'external_reference' => 'ETOTIM',
            'pickup_at' => '2025-03-24 21:05:00',
            'pickup_address' => '1 Test St, Sheffield',
            'destination_address' => 'Manchester Airport',
            'final_price' => 200,
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_4',
            'title' => '*Jo Manchester Airport (EXEC)*',
            'location' => 'Manchester Airport',
            'description' => '📑 Booking Confirmation',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        app(EtoAuditService::class)->audit(
            $this->csvPath("24/03/2025 22:05;ETOTIM;Jo;Completed;200.00;\"Paid\"\n")
        );

        $issues = implode(' ', $booking->fresh()->meta['audit_issues'] ?? []);
        $this->assertStringNotContainsString('time differs', $issues);
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
