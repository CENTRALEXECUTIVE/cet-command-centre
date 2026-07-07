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
