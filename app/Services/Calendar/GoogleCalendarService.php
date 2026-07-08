<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
     * Whether the calendar may be touched at all. Paused by default — a hard
     * safety switch so the system NEVER reads or writes the calendar unless it
     * has been deliberately resumed (cet:calendar-resume). The env override
     * CALENDAR_SYNC_ENABLED=false forces it off no matter what.
     */
    public function active(): bool
    {
        if (config('services.google_calendar.sync_enabled') === false) {
            return false;
        }

        // Active by default — the operator has opted in to auto-adding new
        // bookings in the correct format. cet:calendar-pause flips it off; the
        // CALENDAR_SYNC_ENABLED=false env override is the hard kill.
        return ! Setting::get('calendar_paused', false);
    }

    /**
     * Sync one calendar event to Google. Returns true on success.
     */
    public function push(CalendarEvent $event): bool
    {
        if (! $this->active()) {
            return false; // calendar paused — leave the event pending, touch nothing
        }
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
                'description' => $event->description,
                'start' => ['dateTime' => $event->start_at->toRfc3339String(), 'timeZone' => $event->timezone],
                'end' => ['dateTime' => $event->end_at->toRfc3339String(), 'timeZone' => $event->timezone],
                'reminders' => ['useDefault' => false, 'overrides' => $event->notifications ?? []],
            ];

            $calendarId = rawurlencode($event->calendar_id);
            $base = "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events";

            // No id stored yet → before adding, check whether this exact booking
            // (same reference) is ALREADY on the calendar — e.g. imported by hand
            // or left by an earlier run. If so, adopt its id and UPDATE it rather
            // than posting a second copy. This is what stops duplicates for good.
            if (! $event->google_event_id) {
                $existingId = $this->findEventIdByReference($token, $base, $event);
                if ($existingId) {
                    $event->google_event_id = $existingId;
                }
            }

            $response = $event->google_event_id
                ? Http::withToken($token)->put("{$base}/{$event->google_event_id}", $payload)
                : Http::withToken($token)->post($base, $payload);

            if ($response->successful()) {
                $eventId = $response->json('id', $event->google_event_id);

                // Store the Google event id IMMEDIATELY. Critical for no-duplicates:
                // a later email for the same reference (e.g. payment now Paid) must
                // UPDATE this same event (PUT), never create a second one. We save
                // the id even if the confirmation read-back below hiccups.
                $event->update([
                    'google_event_id' => $eventId,
                    'sync_status' => 'synced',
                    'synced_at' => now(),
                    'sync_error' => null,
                ]);

                // Rule: confirm it's actually in the calendar by reading it back.
                if (! $this->confirmInCalendar($token, $base, $eventId)) {
                    Log::warning('Google Calendar event written but not confirmed on read-back', [
                        'event' => $event->id, 'google_event_id' => $eventId,
                    ]);
                }

                return true;
            }

            return $this->markFailed($event, $response->body());
        } catch (\Throwable $e) {
            return $this->markFailed($event, $e->getMessage());
        }
    }

    /**
     * Find an event already on the calendar that belongs to the SAME booking,
     * matched by the booking reference printed in its description ("Booking
     * Reference: XXX"). Catches events the operator imported by hand or that an
     * earlier run created but we no longer track — so we update in place instead
     * of creating a duplicate. Returns the Google event id, or null if none.
     */
    protected function findEventIdByReference(string $token, string $base, CalendarEvent $event): ?string
    {
        $ref = $event->booking?->external_reference ?: $event->booking?->reference;
        if (blank($ref)) {
            return null;
        }

        $resp = Http::withToken($token)->get($base, [
            'q' => $ref,
            'singleEvents' => 'true',
            'showDeleted' => 'false',
            'maxResults' => 50,
        ]);
        if (! $resp->successful()) {
            return null;
        }

        foreach ($resp->json('items', []) as $item) {
            $description = (string) ($item['description'] ?? '');
            // Require the reference to appear as the booking reference, not just
            // anywhere — avoids matching an unrelated event that mentions the code.
            if (! empty($item['id'])
                && preg_match('/Booking Reference:\*?\s*'.preg_quote($ref, '/').'\b/i', $description)) {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * Read the event back from Google to confirm the add/update actually landed
     * (status is "confirmed", not cancelled). Returns false if it can't be found.
     */
    protected function confirmInCalendar(string $token, string $base, ?string $eventId): bool
    {
        if (! $eventId) {
            return false;
        }

        $check = Http::withToken($token)->get("{$base}/{$eventId}");

        return $check->successful() && $check->json('status') !== 'cancelled';
    }

    /**
     * READ-ONLY: fetch a tracked event from Google exactly as it stands on the
     * calendar right now — including any time the operator corrected there by
     * hand. Returns its start/end (in the app timezone), title, location and
     * description, or null if the calendar is inactive/unconfigured, the event
     * isn't tracked, or it can't be read. NEVER writes to the calendar.
     *
     * @return array{start: Carbon, end: ?Carbon, title: ?string, location: ?string, description: ?string}|null
     */
    public function readEvent(CalendarEvent $event): ?array
    {
        if (! $this->active() || ! $this->configured() || blank($event->google_event_id)) {
            return null;
        }

        try {
            $token = $this->accessToken();
            if (! $token) {
                return null;
            }

            $calendarId = rawurlencode($event->calendar_id);
            $base = "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events";
            $resp = Http::withToken($token)->get("{$base}/{$event->google_event_id}");

            if (! $resp->successful() || $resp->json('status') === 'cancelled') {
                return null;
            }

            // Timed events carry start.dateTime; all-day events carry start.date.
            $start = $resp->json('start.dateTime') ?? $resp->json('start.date');
            if (! $start) {
                return null;
            }
            $end = $resp->json('end.dateTime') ?? $resp->json('end.date');

            return [
                'start' => Carbon::parse($start)->setTimezone(config('app.timezone')),
                'end' => $end ? Carbon::parse($end)->setTimezone(config('app.timezone')) : null,
                'title' => $resp->json('summary'),
                'location' => $resp->json('location'),
                'description' => $resp->json('description'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Google Calendar read failed', ['event' => $event->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Remove an event from Google Calendar (used when cleaning up demo/removed
     * bookings). Treats "already gone" (404/410) as success.
     */
    public function delete(CalendarEvent $event): bool
    {
        if (! $this->active() || ! $this->configured() || ! $event->google_event_id) {
            return false;
        }

        try {
            $token = $this->accessToken();
            if (! $token) {
                return false;
            }
            $calendarId = rawurlencode($event->calendar_id);
            $response = Http::withToken($token)->delete(
                "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$event->google_event_id}"
            );

            return $response->successful() || in_array($response->status(), [404, 410], true);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar delete failed', ['event' => $event->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * READ-ONLY: list events between two instants (expanded to single events,
     * ordered by start). Used to report figures straight from the calendar — the
     * operator's source of truth — without changing anything. Returns raw Google
     * event arrays, or [] if the calendar isn't configured/reachable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventsBetween(string $calendarId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if (! $this->configured()) {
            return [];
        }
        $token = $this->accessToken();
        if (! $token) {
            return [];
        }

        $base = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
        $events = [];
        $pageToken = null;
        do {
            $resp = Http::withToken($token)->get($base, array_filter([
                'timeMin' => $from->format(\DateTimeInterface::RFC3339),
                'timeMax' => $to->format(\DateTimeInterface::RFC3339),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));
            if (! $resp->successful()) {
                break;
            }
            foreach ($resp->json('items', []) as $item) {
                $events[] = $item;
            }
            $pageToken = $resp->json('nextPageToken');
        } while ($pageToken);

        return $events;
    }

    /**
     * Delete EVERY event this system created on the calendar (matched by the
     * service-account being the event creator), regardless of whether we still
     * track its id — this removes orphaned duplicates left by earlier runs.
     * Personal events created by a human are left untouched.
     */
    public function purgeOwnEvents(string $calendarId): int
    {
        if (! $this->active() || ! $this->configured()) {
            return 0;
        }
        $token = $this->accessToken();
        $creds = $this->credentials();
        if (! $token || empty($creds['client_email'])) {
            return 0;
        }

        $serviceEmail = strtolower($creds['client_email']);
        $base = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';

        // Collect every event id created by the service account (paginated).
        $ids = [];
        $pageToken = null;
        do {
            $resp = Http::withToken($token)->get($base, array_filter([
                'maxResults' => 2500,
                'singleEvents' => 'true',
                'showDeleted' => 'false',
                'pageToken' => $pageToken,
            ]));
            if (! $resp->successful()) {
                break;
            }
            foreach ($resp->json('items', []) as $item) {
                $creator = strtolower($item['creator']['email'] ?? '');
                // Ours = created by the service account ONLY. We must NOT match on
                // the title format (*Name AIRPORT (TAG)*): the operator's own
                // historical bookings use that exact bold style, and matching it
                // here would delete real jobs. Use cet:purge-calendar --all for a
                // deliberate full wipe.
                if (! empty($item['id']) && $creator === $serviceEmail) {
                    $ids[] = $item['id'];
                }
            }
            $pageToken = $resp->json('nextPageToken');
        } while ($pageToken);

        $deleted = 0;
        foreach (array_unique($ids) as $id) {
            $d = Http::withToken($token)->delete("{$base}/".rawurlencode($id));
            if ($d->successful() || in_array($d->status(), [404, 410], true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete EVERY event on the calendar, no matter who created it — a complete
     * wipe used to restore the calendar to an exact known state from an export
     * (cet:import-ics). Use with care; pairs with a full re-import afterwards.
     */
    public function purgeAllEvents(string $calendarId): int
    {
        if (! $this->active() || ! $this->configured()) {
            return 0;
        }
        $token = $this->accessToken();
        if (! $token) {
            return 0;
        }

        $base = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';

        $deleted = 0;
        // Re-list from the first page each loop: deletions shrink the set, so we
        // keep fetching+deleting until the calendar reports no more events.
        do {
            $resp = Http::withToken($token)->get($base, [
                'maxResults' => 2500,
                'singleEvents' => 'true',
                'showDeleted' => 'false',
            ]);
            if (! $resp->successful()) {
                break;
            }
            $items = $resp->json('items', []);
            foreach ($items as $item) {
                if (empty($item['id'])) {
                    continue;
                }
                $d = Http::withToken($token)->delete("{$base}/".rawurlencode($item['id']));
                if ($d->successful() || in_array($d->status(), [404, 410], true)) {
                    $deleted++;
                }
            }
        } while (! empty($items));

        return $deleted;
    }

    /**
     * Step-by-step check used by `cet:test-calendar`: read the key file → sign a
     * JWT → get a token → read the target calendar. Reports the real Google error
     * at whichever step fails. Reveals no private key.
     *
     * @return array<string, mixed>
     */
    public function diagnose(string $calendarId): array
    {
        $out = ['configured' => $this->configured(), 'calendar_id' => $calendarId];
        if (! $this->configured()) {
            return $out + ['result' => 'GOOGLE_CALENDAR_CREDENTIALS is not set in .env.'];
        }

        $creds = $this->credentials();
        $out['key_file_readable'] = $creds !== null;
        if (! $creds) {
            return $out + ['result' => 'The google-calendar.json key file is missing, unreadable, or not valid JSON.'];
        }
        $out['client_email'] = $creds['client_email'] ?? null;
        $out['has_private_key'] = ! empty($creds['private_key']);

        $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $claims = [
            'iss' => $creds['client_email'] ?? '',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $signingInput = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url(json_encode($claims));
        $signature = '';
        if (! openssl_sign($signingInput, $signature, $creds['private_key'] ?? '', 'sha256WithRSAEncryption')) {
            return $out + ['result' => 'Signing failed — the private_key in the key file is invalid.', 'openssl' => openssl_error_string()];
        }
        $jwt = $signingInput.'.'.$this->base64Url($signature);

        $tokenResponse = Http::asForm()->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        $out['token_http'] = $tokenResponse->status();
        if (! $tokenResponse->successful()) {
            return $out + [
                'token_error' => $tokenResponse->json('error_description') ?? substr($tokenResponse->body(), 0, 300),
                'result' => 'Google rejected the token request (often: Calendar API not enabled, or server clock is wrong).',
            ];
        }

        $cal = Http::withToken($tokenResponse->json('access_token'))
            ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId));
        $out['calendar_http'] = $cal->status();
        if (! $cal->successful()) {
            return $out + [
                'calendar_error' => substr($cal->body(), 0, 300),
                'result' => 'Token works, but the calendar is not accessible — share '
                    .($creds['client_email'] ?? 'the service account').' on the calendar with "Make changes to events", and check CET_CALENDAR_ID.',
            ];
        }

        return $out + ['result' => 'Google Calendar fully working — events will sync.'];
    }

    /**
     * Obtain an access token from the service-account credentials via a signed
     * JWT (RS256) — no extra packages. The credentials value may be a path to
     * the service-account JSON or the JSON itself. Cached for ~50 minutes.
     *
     * The target calendar (config cet.calendar_id) must be shared with the
     * service account's client_email, with "Make changes to events" permission.
     */
    protected function accessToken(): ?string
    {
        return Cache::remember('google_calendar_token', 3000, function () {
            $creds = $this->credentials();
            if (! $creds || empty($creds['client_email']) || empty($creds['private_key'])) {
                return null;
            }

            $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $claims = [
                'iss' => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/calendar',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode($claims)),
            ];
            $signingInput = implode('.', $segments);

            $signature = '';
            if (! openssl_sign($signingInput, $signature, $creds['private_key'], 'sha256WithRSAEncryption')) {
                return null;
            }
            $jwt = $signingInput.'.'.$this->base64Url($signature);

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->successful() ? $response->json('access_token') : null;
        });
    }

    /** @return array<string, mixed>|null */
    private function credentials(): ?array
    {
        $value = config('services.google_calendar.credentials');
        if (blank($value)) {
            return null;
        }
        $json = (is_string($value) && is_readable($value)) ? file_get_contents($value) : $value;
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function markFailed(CalendarEvent $event, string $reason): bool
    {
        Log::warning('Google Calendar sync failed', ['event' => $event->id, 'reason' => $reason]);
        $event->update(['sync_status' => 'failed', 'sync_error' => substr($reason, 0, 1000)]);

        return false;
    }
}
