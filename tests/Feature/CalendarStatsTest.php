<?php

namespace Tests\Feature;

use App\Services\Calendar\CalendarStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-07-15 10:00:00', 'Europe/London'));

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        config(['services.google_calendar.credentials' => json_encode([
            'client_email' => 'svc@proj.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ])]);
    }

    public function test_counts_come_from_the_calendar(): void
    {
        $events = [
            // Today, has a driver (ABDI) → counts as a job today, NOT awaiting.
            ['start' => ['dateTime' => '2026-07-15T14:00:00+01:00'], 'summary' => '*Alice MAN (ABDI)*', 'description' => 'Booking Reference: A1'],
            // Today, vehicle tag → job today AND awaiting (needs a driver).
            ['start' => ['dateTime' => '2026-07-15T15:00:00+01:00'], 'summary' => '*Bob FREE ROAM (MINIBUS)*', 'description' => 'Booking Reference: B2'],
            // Upcoming, vehicle tag → awaiting.
            ['start' => ['dateTime' => '2026-07-20T09:00:00+01:00'], 'summary' => '*Carol LBA (V CLASS)*', 'description' => 'Booking Reference: C3'],
            // Upcoming, has a driver (MAJ) → not awaiting.
            ['start' => ['dateTime' => '2026-07-20T10:00:00+01:00'], 'summary' => '*Dave MAN (MAJ)*', 'description' => 'Booking Reference: D4'],
            // A holiday / all-day entry → ignored (no dateTime, not our format).
            ['start' => ['date' => '2026-07-16'], 'summary' => 'Bank Holiday'],
        ];

        Http::fake(function ($request) use ($events) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200);
            }

            return Http::response(['items' => $events], 200);
        });

        $counts = app(CalendarStats::class)->counts();

        $this->assertSame(2, $counts['jobsToday'], 'two booking events start today');
        $this->assertSame(2, $counts['awaiting'], 'two upcoming/today jobs have no named driver');
    }

    public function test_upcoming_list_comes_from_the_calendar_in_order(): void
    {
        $events = [
            ['start' => ['dateTime' => '2026-07-20T10:00:00+01:00'], 'summary' => '*Dave MAN (MAJ)*',
                'description' => "• *Customer Name:* Dave Smith\n• *Vehicle Type:* Executive\n• *Booking Reference:* D4"],
            ['start' => ['dateTime' => '2026-07-16T09:00:00+01:00'], 'summary' => '*Bob FREE ROAM (MINIBUS)*',
                'description' => "• *Customer Name:* Bob Jones\n• *Vehicle Type:* Minibus\n• *Booking Reference:* B2"],
            // A past event → excluded from upcoming.
            ['start' => ['dateTime' => '2026-07-01T09:00:00+01:00'], 'summary' => '*Old Job MAN (ABDI)*',
                'description' => '• *Booking Reference:* OLD'],
        ];

        Http::fake(function ($request) use ($events) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200);
            }

            return Http::response(['items' => $events], 200);
        });

        $upcoming = app(CalendarStats::class)->upcoming(10);

        $this->assertCount(2, $upcoming); // past one excluded
        // Sorted by start: Bob (16th) before Dave (20th).
        $this->assertSame('B2', $upcoming[0]['ref']);
        $this->assertSame('Bob Jones', $upcoming[0]['customer']);
        $this->assertSame('—', $upcoming[0]['driver']); // MINIBUS is a vehicle, not a driver
        $this->assertSame('D4', $upcoming[1]['ref']);
        $this->assertSame('MAJ', $upcoming[1]['driver']); // person tag
    }

    public function test_returns_null_when_calendar_not_configured(): void
    {
        config(['services.google_calendar.credentials' => null]);

        $this->assertNull(app(CalendarStats::class)->counts());
        $this->assertNull(app(CalendarStats::class)->upcoming());
    }
}
