<?php

namespace App\Services\Calendar;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard figures read STRAIGHT FROM the Google Calendar — the operator's
 * source of truth — so "Jobs today" etc. always match what's on the calendar.
 * Read-only: it never changes a single event. Cached briefly so the dashboard
 * stays fast, and it degrades gracefully (returns null) when the calendar isn't
 * configured, letting the caller fall back to the database.
 */
class CalendarStats
{
    public function __construct(private readonly GoogleCalendarService $calendar) {}

    public function available(): bool
    {
        return $this->calendar->configured();
    }

    /**
     * Counts derived from the calendar: jobs today, and upcoming jobs still
     * needing a driver. Returns null when the calendar can't be read.
     *
     * @return array{jobsToday: int, awaiting: int}|null
     */
    public function counts(): ?array
    {
        if (! $this->available()) {
            return null;
        }

        return Cache::remember('calendar_stats_counts', 300, function () {
            $calendarId = Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
            $now = Carbon::now('Europe/London');
            // Window: start of today → 6 months out (covers today + all upcoming).
            $events = $this->calendar->eventsBetween(
                $calendarId,
                $now->copy()->startOfDay(),
                $now->copy()->addMonths(6)->endOfDay(),
            );

            $bookings = array_filter($events, fn ($e) => $this->isBooking($e));

            $jobsToday = 0;
            $awaiting = 0;
            foreach ($bookings as $e) {
                $start = $this->startOf($e);
                if ($start === null) {
                    continue;
                }
                if ($start->isSameDay($now)) {
                    $jobsToday++;
                }
                if ($start->greaterThanOrEqualTo($now) && ! $this->isAllocated($e['summary'] ?? '')) {
                    $awaiting++;
                }
            }

            return ['jobsToday' => $jobsToday, 'awaiting' => $awaiting];
        });
    }

    /** A CET booking event = timed event whose title is our format or that carries a reference. */
    private function isBooking(array $event): bool
    {
        if (! isset($event['start']['dateTime'])) {
            return false; // skip all-day / holiday entries
        }
        $summary = trim((string) ($event['summary'] ?? ''));
        $description = (string) ($event['description'] ?? '');

        return str_starts_with($summary, '*') || str_contains($description, 'Booking Reference');
    }

    private function startOf(array $event): ?Carbon
    {
        $dt = $event['start']['dateTime'] ?? null;

        return $dt ? Carbon::parse($dt)->setTimezone('Europe/London') : null;
    }

    /** Allocated = the title bracket names a person, not a vehicle / COVER / blank. */
    private function isAllocated(string $summary): bool
    {
        if (! preg_match('/\(([^)]+)\)\*?\s*$/', $summary, $m)) {
            return false;
        }
        $tag = strtoupper(trim($m[1]));

        // Vehicle types and COVER are "not yet a named driver".
        return $tag !== '' && ! preg_match('/CLASS|MINIBUS|EXECUTIVE|ESTATE|ROLLS|SEATER|SALOON|MPV|COVER|V14/', $tag);
    }
}
