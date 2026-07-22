<?php

namespace App\Services\Calendar;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Setting;

/**
 * Pulls a booking's pickup time from the LIVE Google Calendar event so the
 * Command Centre matches what's on the calendar — the operator's source of
 * truth. Strictly read-only against Google: it reads the event and updates our
 * own records (the booking and our local event copy), never the calendar.
 */
class CalendarTimeSync
{
    /** Diagnostics from the last live search, surfaced when a scan can't match. */
    private array $lastDiag = [];

    public function __construct(private readonly GoogleCalendarService $google) {}

    /** The calendar the events live on (Setting override, else the CET default). */
    private function calendarId(): string
    {
        return (string) Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
    }

    /**
     * Find the live Google event for a booking even when we never stored its id
     * — by the ETO/booking reference printed in the event description — and make
     * sure the booking has a linked CalendarEvent row carrying that id, so every
     * later read/scan is a direct hit. Returns the linked event, or null when
     * the calendar can't be read or no matching event exists.
     */
    /**
     * Find the LIVE event for a booking, reference first — ALWAYS. The booking
     * reference printed on the calendar event is the source of truth; a stored
     * google_event_id is only a hint and is NEVER trusted over a reference
     * match. This is what fixes the "two events for one booking" trap: if our
     * stored id points at a stale duplicate (e.g. one our own push created)
     * while the operator maintains a different event, the reference match wins
     * and the booking is re-linked to the real event.
     *
     * @return array{live: ?array<string,mixed>, relinked: bool}
     */
    private function resolveLiveEvent(Booking $booking, ?array $candidateEvents): array
    {
        $event = $booking->calendarEvent;
        $reference = $booking->external_reference ?: $booking->reference;

        // 1. Reference-first: in-memory candidates (bulk), then Google's own
        //    full-text search across the whole calendar.
        $match = null;
        if ($candidateEvents !== null) {
            $match = $this->google->matchIn($candidateEvents, $reference, $booking->displayName());
            $this->lastDiag = ['read' => true, 'ref_hits' => count($candidateEvents), 'matched' => $match ? 'reference' : null];
        }
        if (! $match) {
            $found = $this->google->findEventWithDiagnostics(
                $this->calendarId(), $reference, $booking->displayName(), $booking->pickup_at ?? now()
            );
            $match = $found['event'];
            $this->lastDiag = $found['diag'];
        }

        if ($match) {
            $relinked = $event !== null
                && filled($event->google_event_id)
                && $event->google_event_id !== $match['id'];

            return ['live' => $match, 'relinked' => $relinked];
        }

        // 2. No reference match anywhere — fall back to the stored id (the
        //    event may simply not carry the reference in its text).
        if ($event && filled($event->google_event_id)) {
            $live = $this->google->readEvent($event);
            if ($live) {
                return ['live' => $live + ['id' => $event->google_event_id], 'relinked' => false];
            }
        }

        return ['live' => null, 'relinked' => false];
    }

    /**
     * Set the booking's pickup time to the calendar's authoritative time (the
     * printed "Date & Time" where present, else the slot), and bring the local
     * event slot into line with it too — so the booking, the slot and the printed
     * time all agree at the correct hour. No Google call, so it's instant for the
     * bulk/auto heal. Returns true when the booking's own time changed.
     */
    public function alignToCalendarSlot(Booking $booking): bool
    {
        $event = $booking->calendarEvent;
        if (! $event) {
            return false;
        }
        $target = $event->calendarPickupAt();
        if (! $target || ! $booking->pickup_at) {
            return false;
        }

        $bookingChanged = $booking->pickup_at->format('Y-m-d H:i') !== $target->format('Y-m-d H:i');
        $slotChanged = ! $event->start_at || $event->start_at->format('Y-m-d H:i') !== $target->format('Y-m-d H:i');

        if ($bookingChanged) {
            $booking->forceFill(['pickup_at' => $target])->save();
        }

        // Correct our local slot to the true time as well, so the "calendar's own
        // time and description disagree" flag clears. This touches OUR row only.
        if ($slotChanged) {
            $event->forceFill(['start_at' => $target, 'end_at' => $target->copy()->addHour()])->save();
        }

        // Drop any stale "one hour" time warnings now the time is correct, so the
        // ⚠ clears immediately on every path (not only when the audit is re-run).
        if ($bookingChanged || $slotChanged) {
            $this->clearTimeWarnings($booking);
        }

        return $bookingChanged;
    }

    /** Remove time-mismatch entries from a booking's stored audit issues. */
    private function clearTimeWarnings(Booking $booking): void
    {
        $issues = $booking->meta['audit_issues'] ?? [];
        if (! $issues) {
            return;
        }
        $kept = array_values(array_filter($issues, fn ($i) => ! str_contains($i, "doesn't match the booking")
            && ! str_contains($i, 'Calendar description time')
            && ! str_contains($i, 'Calendar time')));

        if (count($kept) !== count($issues)) {
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['audit_issues' => $kept])])->save();
        }
    }

    /**
     * Full verification scan against the LIVE calendar (read-only on Google):
     * reads the event as it stands right now and brings OUR copy — booking
     * pickup time, event title, location, slot times and the whole description
     * — exactly into line with it. Returns a per-field report of what was
     * corrected so the operator sees precisely what was wrong.
     *
     * @return array{status: 'ok'|'unavailable', changes: array<string, array{from: ?string, to: ?string}>, diag: array<string,mixed>}
     */
    public function scan(Booking $booking, ?array $candidateEvents = null): array
    {
        $this->lastDiag = [];

        if (! $this->google->configured() || ! $this->google->active()) {
            $this->flagUnverified($booking);

            return ['status' => 'unavailable', 'changes' => [], 'diag' => $this->lastDiag];
        }

        // Reference-first live lookup — a stored event id is never trusted over
        // the event that actually carries this booking's reference.
        ['live' => $live, 'relinked' => $relinked] = $this->resolveLiveEvent($booking, $candidateEvents);

        if (! $live) {
            // Couldn't confirm against the LIVE calendar. Never present the
            // stored copy as verified — flag it so a stale time (e.g. an edit
            // made on the calendar) is visibly UNVERIFIED, not silently trusted.
            $this->flagUnverified($booking);

            return ['status' => 'unavailable', 'changes' => [], 'diag' => $this->lastDiag];
        }

        $changes = [];
        $event = $booking->calendarEvent;

        if (! $event) {
            $event = new CalendarEvent(['booking_id' => $booking->id]);
            $event->forceFill([
                'booking_id' => $booking->id,
                'calendar_id' => $this->calendarId(),
                'google_event_id' => $live['id'],
                'title' => $live['title'] ?? '',
                'location' => $live['location'] ?? null,
                'description' => $live['description'] ?? null,
                'start_at' => $live['start'],
                'end_at' => ($live['end'] ?? null) ?: $live['start']->copy()->addHour(),
                'sync_status' => 'synced',
            ])->save();
            $booking->setRelation('calendarEvent', $event);
            $changes['Calendar event'] = ['from' => 'not linked', 'to' => 'matched by reference'];
        } elseif ($relinked) {
            // The stored id pointed at a DIFFERENT event than the one carrying
            // this booking's reference — e.g. a stale duplicate. The reference
            // event wins; tell the operator so they can bin the duplicate.
            $changes['Calendar event'] = [
                'from' => 'was linked to a different event (possible duplicate on the calendar — remove the old one by hand)',
                'to' => 'now linked to the event carrying '.($booking->external_reference ?: $booking->reference),
            ];
            $event->forceFill(['google_event_id' => $live['id'], 'sync_status' => 'synced'])->save();
        }

        // 1. Pickup time — the calendar's start is the truth.
        $liveStart = $live['start'];
        if (! $booking->pickup_at || $booking->pickup_at->format('Y-m-d H:i') !== $liveStart->format('Y-m-d H:i')) {
            $changes['Pickup time'] = [
                'from' => $booking->pickup_at?->format('D d M Y, H:i'),
                'to' => $liveStart->format('D d M Y, H:i'),
            ];
            $booking->forceFill(['pickup_at' => $liveStart])->save();
        }

        // 2. Our stored copy of the event — title, location, slot, description.
        $mirror = [];
        if (filled($live['title'] ?? null) && $live['title'] !== $event->title) {
            $changes['Title'] = ['from' => $event->title, 'to' => $live['title']];
            $mirror['title'] = $live['title'];
        }
        if (filled($live['location'] ?? null) && $live['location'] !== $event->location) {
            $changes['Pickup location'] = ['from' => $event->location, 'to' => $live['location']];
            $mirror['location'] = $live['location'];
        }
        if (! $event->start_at || $event->start_at->format('Y-m-d H:i') !== $liveStart->format('Y-m-d H:i')) {
            $mirror['start_at'] = $liveStart;
            $mirror['end_at'] = ($live['end'] ?? null) ?: $liveStart->copy()->addHour();
        }
        if (filled($live['description'] ?? null) && trim((string) $live['description']) !== trim((string) $event->description)) {
            $changes['Details block'] = ['from' => 'out of date', 'to' => 'refreshed from the calendar'];
            $mirror['description'] = $live['description'];
        }
        if ($mirror !== []) {
            $event->forceFill($mirror)->save();
        }

        // 3. Clear stale time warnings, drop the unverified flag, and stamp the
        //    verification — the booking now provably matches the live calendar.
        if ($changes !== []) {
            $this->clearTimeWarnings($booking);
        }
        $meta = array_merge($booking->meta ?? [], ['calendar_scanned_at' => now()->toDateTimeString()]);
        unset($meta['calendar_unverified']);
        $booking->forceFill(['meta' => $meta])->save();

        return ['status' => 'ok', 'changes' => $changes, 'diag' => $this->lastDiag];
    }

    /**
     * Mark that we could NOT confirm this booking against the live calendar, so
     * the booking page can warn that the time/details shown are unverified (and
     * may be stale) rather than presenting them as correct.
     */
    private function flagUnverified(Booking $booking): void
    {
        // Only flag bookings that are supposed to be on the calendar.
        if (blank($booking->external_reference) && ! $booking->calendarEvent) {
            return;
        }
        if (! empty($booking->meta['calendar_unverified'])) {
            return; // already flagged
        }
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'calendar_unverified' => now()->toDateTimeString(),
        ])])->save();
    }

    /**
     * Set the booking's pickup time to the live calendar event's start time.
     * The local event copy is refreshed too (start/end) so the ETO audit stays
     * consistent — that is a change to OUR row, not a write to Google.
     *
     * @return array{status: 'updated'|'matches'|'unavailable', old: ?string, new: ?string}
     */
    public function pullTime(Booking $booking): array
    {
        $event = $booking->calendarEvent;
        if (! $event || blank($event->google_event_id)) {
            return ['status' => 'unavailable', 'old' => null, 'new' => null];
        }

        $live = $this->google->readEvent($event);
        if (! $live) {
            return ['status' => 'unavailable', 'old' => null, 'new' => null];
        }

        // Mirror the live description into our local copy too, so the luggage and
        // other details shown in the command centre match the calendar. This
        // updates OUR row only — it is never a write to Google.
        if (filled($live['description'] ?? null)) {
            $event->description = $live['description'];
        }

        $calendarStart = $live['start'];
        $old = $booking->pickup_at;

        // Compare to the minute — Google may hand back seconds we don't store.
        if ($old && $old->format('Y-m-d H:i') === $calendarStart->format('Y-m-d H:i')) {
            $event->save(); // persist any description refresh even when the time already matches
            return [
                'status' => 'matches',
                'old' => $old->format('D d M, H:i'),
                'new' => $calendarStart->format('D d M, H:i'),
            ];
        }

        $booking->forceFill(['pickup_at' => $calendarStart])->save();
        $event->forceFill([
            'start_at' => $calendarStart,
            'end_at' => $calendarStart->copy()->addHour(),
        ])->save();

        return [
            'status' => 'updated',
            'old' => $old?->format('D d M, H:i'),
            'new' => $calendarStart->format('D d M, H:i'),
        ];
    }
}
