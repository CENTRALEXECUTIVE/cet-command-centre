<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
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
                'description' => $event->description,
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
                $eventId = $response->json('id', $event->google_event_id);

                // Rule: after every add, confirm it's actually in the calendar by
                // reading the event straight back from Google before marking synced.
                if (! $this->confirmInCalendar($token, $base, $eventId)) {
                    return $this->markFailed($event, 'Event written but not confirmed present in calendar.');
                }

                $event->update([
                    'google_event_id' => $eventId,
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
