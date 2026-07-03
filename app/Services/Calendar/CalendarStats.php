<?php

namespace App\Services\Calendar;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dashboard figures read STRAIGHT FROM the Google Calendar — the operator's
 * source of truth — so "Jobs today", "Awaiting allocation" and the upcoming
 * list always match what's on the calendar. Read-only: it never changes a
 * single event. The event fetch is cached briefly so the dashboard stays fast,
 * and everything degrades gracefully (returns null) when the calendar isn't
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
        $events = $this->bookingEvents();
        if ($events === null) {
            return null;
        }

        $now = Carbon::now('Europe/London');
        $jobsToday = 0;
        $awaiting = 0;
        foreach ($events as $e) {
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
    }

    /**
     * The next $limit upcoming jobs, straight from the calendar, normalised into
     * display rows (and linked to the matching database booking where one
     * exists). Returns null when the calendar can't be read.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function upcoming(int $limit = 10): ?array
    {
        $events = $this->bookingEvents();
        if ($events === null) {
            return null;
        }

        $now = Carbon::now('Europe/London');
        $upcoming = [];
        foreach ($events as $e) {
            $start = $this->startOf($e);
            if ($start !== null && $start->greaterThanOrEqualTo($now)) {
                $upcoming[] = ['event' => $e, 'start' => $start];
            }
        }
        usort($upcoming, fn ($a, $b) => $a['start'] <=> $b['start']);

        return array_map(fn ($row) => $this->row($row['event'], $row['start']), array_slice($upcoming, 0, $limit));
    }

    /** Fetch + cache the calendar's booking events once (shared by counts/upcoming). */
    private function bookingEvents(): ?array
    {
        if (! $this->available()) {
            return null;
        }

        return Cache::remember('calendar_stats_events', 300, function () {
            $calendarId = Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
            $now = Carbon::now('Europe/London');
            $events = $this->calendar->eventsBetween(
                $calendarId,
                $now->copy()->startOfDay(),
                $now->copy()->addMonths(6)->endOfDay(),
            );

            return array_values(array_filter($events, fn ($e) => $this->isBooking($e)));
        });
    }

    /** Normalise a calendar event into a display row, linked to its booking if known. */
    private function row(array $event, Carbon $start): array
    {
        $summary = (string) ($event['summary'] ?? '');
        $description = (string) ($event['description'] ?? '');
        $ref = $this->field($description, 'Booking Reference');

        $booking = $ref ? Booking::where('external_reference', $ref)->first() : null;

        return [
            'ref' => $ref ?? '—',
            'pickup' => $start,
            'customer' => $this->field($description, 'Customer Name') ?? $this->nameFromTitle($summary),
            'vehicle' => $this->field($description, 'Vehicle Type') ?? '—',
            'driver' => $this->allocatedTag($summary) ?? '—',
            'status' => $booking?->status?->label() ?? 'Scheduled',
            'url' => $booking ? route('bookings.show', $booking) : null,
        ];
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

    /** The bracket tag, only if it names a person (not a vehicle / COVER / blank). */
    private function allocatedTag(string $summary): ?string
    {
        if (! preg_match('/\(([^)]+)\)\*?\s*$/', $summary, $m)) {
            return null;
        }
        $tag = strtoupper(trim($m[1]));
        if ($tag === '' || preg_match('/CLASS|MINIBUS|EXECUTIVE|ESTATE|ROLLS|SEATER|SALOON|MPV|COVER|V14/', $tag)) {
            return null;
        }

        return $tag;
    }

    private function isAllocated(string $summary): bool
    {
        return $this->allocatedTag($summary) !== null;
    }

    /** Value of a "• *Label:* value" line from the description (plain text). */
    private function field(string $description, string $label): ?string
    {
        if (preg_match('/'.preg_quote($label, '/').':\*?\s*(.+)/', $description, $m)) {
            return trim(explode("\n", $m[1])[0]) ?: null;
        }

        return null;
    }

    /** Customer name from the title, stripping emojis, airport code and driver tag. */
    private function nameFromTitle(string $summary): string
    {
        $name = trim(preg_replace('/[*👀💰🚼🚸]/u', '', Str::before($summary, ' (')));
        $name = trim(preg_replace('/\s+(MAN|LHR|LGW|STN|EMA|LBA|HUY|LPL|BHX|LTN|FREE ROAM|Return).*$/u', '', $name));

        return $name !== '' ? $name : 'Customer';
    }
}
