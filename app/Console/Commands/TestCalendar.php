<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Console\Command;

/**
 * Diagnoses the Google Calendar connection step by step (key file → JWT sign →
 * access token → read the calendar) so a "Could not obtain access token" can be
 * explained: bad key, Calendar API disabled, clock skew, or the calendar not
 * shared with the service account. Reveals no private key.
 */
class TestCalendar extends Command
{
    protected $signature = 'cet:test-calendar';

    protected $description = 'Check the Google Calendar connection and report what works';

    public function handle(GoogleCalendarService $calendar): int
    {
        $calendarId = Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
        $d = $calendar->diagnose($calendarId);

        $this->line('Configured:        '.($d['configured'] ? 'yes' : 'NO'));
        $this->line('Calendar ID:       '.$d['calendar_id']);
        if (array_key_exists('key_file_readable', $d)) {
            $this->line('Key file readable: '.($d['key_file_readable'] ? 'yes' : 'NO'));
        }
        if (! empty($d['client_email'])) {
            $this->line('Service account:   '.$d['client_email']);
        }
        if (array_key_exists('token_http', $d)) {
            $this->line('Token request:     HTTP '.$d['token_http']);
        }
        if (! empty($d['token_error'])) {
            $this->error('  Token error: '.$d['token_error']);
        }
        if (array_key_exists('calendar_http', $d)) {
            $this->line('Read calendar:     HTTP '.$d['calendar_http']);
        }
        if (! empty($d['calendar_error'])) {
            $this->error('  Calendar error: '.$d['calendar_error']);
        }
        if (! empty($d['openssl'])) {
            $this->error('  OpenSSL: '.$d['openssl']);
        }

        $ok = str_starts_with($d['result'] ?? '', 'Google Calendar fully working');
        $this->newLine();
        $ok ? $this->info($d['result']) : $this->warn($d['result'] ?? 'Unknown result.');

        return self::SUCCESS;
    }
}
