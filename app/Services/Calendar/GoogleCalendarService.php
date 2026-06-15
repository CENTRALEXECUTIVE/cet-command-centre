<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes formatted booking events to the company Google Calendar. The event
 * formatting (bold title, payment emoji, +1h end, notification offsets) is built
 * by CalendarEventBuilder; this performs the API insert/update and records the
 * Google event id. Unconfigured → events are left pending (no-op).
 */
class GoogleCalendarService
{
    public function configured(): bool
    {
        return filled(config('services.google_calendar.credentials'));
    }

    /**
     * Sync one calendar event to Google. Returns true on success.
     */
    public function push(CalendarEvent $event): bool
    {
        if (! $this->configured()) {
            return false; // left pending until credentials are configured
        }

        try {
            $token = $this->accessToken();
            if (! $token) {
                return $this->markFailed($event, 'Could not obtain access token.');
            }

            $payload = [
                'summary' => $event->title,
                'location' => $event->location,
                'start' => ['dateTime' => $event->start_at->toRfc3339String(), 'timeZone' => $event->timezone],
                'end' => ['dateTime' => $event->end_at->toRfc3339String(), 'timeZone' => $event->timezone],
                'reminders' => ['useDefault' => false, 'overrides' => $event->notifications ?? []],
            ];

            $calendarId = rawurlencode($event->calendar_id);
            $base = "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events";

            $response = $event->google_event_id
                ? Http::withToken($token)->put("{$base}/{$event->google_event_id}", $payload)
                : Http::withToken($token)->post($base, $payload);

            if ($response->successful()) {
                $event->update([
                    'google_event_id' => $response->json('id', $event->google_event_id),
                    'sync_status' => 'synced',
                    'synced_at' => now(),
                    'sync_error' => null,
                ]);

                return true;
            }

            return $this->markFailed($event, $response->body());
        } catch (\Throwable $e) {
            return $this->markFailed($event, $e->getMessage());
        }
    }

    /**
     * Obtain an access token from the service-account credentials. Returns null
     * here until the signing implementation/credentials are supplied.
     */
    protected function accessToken(): ?string
    {
        // Integration point: exchange the service-account JWT for an access
        // token (or use a stored OAuth refresh token). Left for live credentials.
        return null;
    }

    private function markFailed(CalendarEvent $event, string $reason): bool
    {
        Log::warning('Google Calendar sync failed', ['event' => $event->id, 'reason' => $reason]);
        $event->update(['sync_status' => 'failed', 'sync_error' => substr($reason, 0, 1000)]);

        return false;
    }
}
