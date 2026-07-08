<?php

namespace App\Services\Calendar;

use App\Models\Booking;

/**
 * Pulls a booking's pickup time from the LIVE Google Calendar event so the
 * Command Centre matches what's on the calendar — the operator's source of
 * truth. Strictly read-only against Google: it reads the event and updates our
 * own records (the booking and our local event copy), never the calendar.
 */
class CalendarTimeSync
{
    public function __construct(private readonly GoogleCalendarService $google) {}

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
     * @return array{status: 'ok'|'unavailable', changes: array<string, array{from: ?string, to: ?string}>}
     */
    public function scan(Booking $booking): array
    {
        $event = $booking->calendarEvent;
        if (! $event || blank($event->google_event_id)) {
            return ['status' => 'unavailable', 'changes' => []];
        }

        $live = $this->google->readEvent($event);
        if (! $live) {
            return ['status' => 'unavailable', 'changes' => []];
        }

        $changes = [];

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

        // 3. Clear stale time warnings and stamp the verification.
        if ($changes !== []) {
            $this->clearTimeWarnings($booking);
        }
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'calendar_scanned_at' => now()->toDateTimeString(),
        ])])->save();

        return ['status' => 'ok', 'changes' => $changes];
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
