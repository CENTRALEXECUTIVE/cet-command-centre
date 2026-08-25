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

    /**
     * Full detail for every job on a given day, straight from the calendar — the
     * complete event description (all fields), plus a link to the booking where
     * one exists. Null when the calendar can't be read.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function jobsOn(Carbon $day): ?array
    {
        $events = $this->bookingEvents();
        if ($events === null) {
            return null;
        }

        $jobs = [];
        foreach ($events as $e) {
            $start = $this->startOf($e);
            if ($start !== null && $start->isSameDay($day)) {
                $jobs[] = ['event' => $e, 'start' => $start];
            }
        }
        usort($jobs, fn ($a, $b) => $a['start'] <=> $b['start']);

        return array_map(function ($j) {
            $row = $this->row($j['event'], $j['start']);
            $row['title'] = trim((string) ($j['event']['summary'] ?? ''), '*');
            $row['location'] = (string) ($j['event']['location'] ?? '');
            $row['description'] = (string) ($j['event']['description'] ?? '');

            return $row;
        }, $jobs);
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

        // Match the calendar event to its booking by either the external (ETO)
        // reference or our own reference, so more day-view jobs are openable.
        $booking = $ref
            ? Booking::where('external_reference', $ref)->orWhere('reference', $ref)->first()
            : null;

        return [
            'ref' => $ref ?? '—',
            'pickup' => $start,
            'customer' => $this->field($description, 'Customer Name') ?? $this->nameFromTitle($summary),
            'vehicle' => $this->field($description, 'Vehicle Type') ?? '—',
            'driver' => $this->allocatedTag($summary) ?? '—',
            'status' => $booking?->status?->label() ?? 'Scheduled',
            'url' => $booking ? route('bookings.show', $booking) : null,
            'event_id' => $event['id'] ?? null,
            'flight' => $this->field($description, 'Flight Number'),
        ];
    }

    /**
     * Parse a specific calendar event (by its Google id) into the fields needed
     * to create a booking from it — so a calendar-only job can be pulled into the
     * system and then edited/messaged. Returns null if the event can't be read.
     *
     * @return array<string, mixed>|null
     */
    public function eventToBookingData(string $eventId): ?array
    {
        foreach ($this->bookingEvents() ?? [] as $event) {
            if (($event['id'] ?? null) !== $eventId) {
                continue;
            }

            return $this->rawEventToBookingData($event);
        }

        return null;
    }

    /**
     * The same parsed shape from a raw, ALREADY-FETCHED Google event item (no
     * extra API call) — lets the scheduled refresh auto-import calendar-only
     * jobs. Returns null for non-booking events (all-day entries etc.).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    public function rawEventToBookingData(array $event): ?array
    {
        if (empty($event['id']) || ! $this->isBooking($event)) {
            return null;
        }

        $summary = (string) ($event['summary'] ?? '');
        $desc = (string) ($event['description'] ?? '');
        $start = $this->startOf($event);
        $endDt = $event['end']['dateTime'] ?? null;
        $end = $endDt ? Carbon::parse($endDt)->setTimezone('Europe/London') : $start?->copy()->addHour();

        return [
            'event_id' => $event['id'],
            'calendar_id' => Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk'),
            'title' => $summary,
            'location' => (string) ($event['location'] ?? ''),
            'description' => $desc,
            'reference' => $this->field($desc, 'Booking Reference'),
            'customer_name' => $this->field($desc, 'Customer Name') ?? $this->nameFromTitle($summary),
            'customer_phone' => $this->field($desc, 'Contact No'),
            'pickup_at' => $start,
            'end_at' => $end,
            'pickup_address' => $this->field($desc, 'Pickup Location') ?: (string) ($event['location'] ?? ''),
            'destination_address' => $this->field($desc, 'Drop-off Location'),
            'passengers' => max(1, (int) preg_replace('/\D/', '', (string) $this->field($desc, 'Passengers'))),
            'luggage' => (int) preg_replace('/\D/', '', (string) $this->field($desc, 'Luggage')),
            'luggage_text' => $this->field($desc, 'Luggage'),
            'flight_number' => $this->field($desc, 'Flight Number'),
            'vehicle_label' => $this->field($desc, 'Vehicle Type'),
            'payment_text' => $this->field($desc, 'Payment'),
            'notes' => $this->field($desc, 'Notes'),
            'driver_tag' => $this->allocatedTag($summary),
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
        // Hyphen/space-tolerant label match, so the calendar's "Drop off Location"
        // (space) is still picked up by "Drop-off Location" and vice-versa —
        // otherwise the field imports as blank/"Unknown".
        $tokens = preg_split('/[\s-]+/', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [$label];
        $labelPattern = implode('[\s-]*', array_map(fn ($t) => preg_quote($t, '/'), $tokens));
        if (preg_match('/'.$labelPattern.'\s*:\*?\s*(.+)/i', $description, $m)) {
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
