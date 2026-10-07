<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\Calendar\CalendarHealth;
use App\Services\Calendar\GoogleCalendarService;
use App\Support\Heartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One place that answers "is anything wrong?" for the System Health page and the
 * admin banner. Every check is wrapped so a single failure (a missing table, an
 * unreachable service) NEVER throws — the business must never go down because a
 * health check misbehaved. Checks are cheap/local (no slow external calls on page
 * load); "connected" means the keys are configured, with on-demand live tests
 * available elsewhere (cet:test-calendar, Square settings).
 *
 * Status values: ok (green), warn (amber), critical (red), off (grey — a feature
 * deliberately not connected yet, e.g. before go-live).
 */
class SystemHealth
{
    /** @return array<int, array{key:string,label:string,status:string,detail:string,group:string}> */
    public function checks(): array
    {
        return [
            $this->scheduler(),
            $this->database(),
            $this->calendarSync(),
            $this->backups(),
            $this->failedMessages(),
            $this->connectivity('calendar', 'Google Calendar', fn () => app(GoogleCalendarService::class)->configured(),
                'Bookings write to the calendar.', 'Add GOOGLE_CALENDAR_CREDENTIALS to switch on.'),
            $this->connectivity('payments', 'Card payments (Square)', fn () => filled(Setting::get('square_access_token') ?: config('services.square.access_token')),
                'Customers can pay card links.', 'Add the Square keys in Settings to switch on.'),
            $this->connectivity('maps', 'Google Maps', fn () => filled(Setting::mapsKey()),
                'Distance pricing, fleet map and geocoding.', 'Add the Maps key in Settings to switch on.'),
            $this->connectivity('telephony', 'Number masking (Twilio)', fn () => filled(config('services.twilio.proxy_service_sid')),
                'Masked calls/texts between driver and customer.', 'Optional — set the Twilio keys to switch on.'),
            $this->emailFeed(),
        ];
    }

    /** The ETO email feed — whether new bookings auto-add from the inbox. */
    private function emailFeed(): array
    {
        try {
            $feed = new \App\Support\EmailFeedStatus;
            if ($feed->connected()) {
                $last = $feed->lastRun();
                $when = $last ? 'last pulled '.$last->diffForHumans() : 'waiting for the first run';

                return $this->row('email_feed', 'ETO email feed', 'ok', 'Connections',
                    'Live — new ETO bookings add themselves ('.$when.').');
            }

            return $this->row('email_feed', 'ETO email feed', 'off', 'Connections',
                'Not connected — new ETO bookings must be added by hand. Missing: '.strtolower((string) $feed->firstProblem()).'.');
        } catch (\Throwable $e) {
            return $this->row('email_feed', 'ETO email feed', 'off', 'Connections', 'Not connected.');
        }
    }

    /** The worst status present, for the banner / badge. */
    public function worst(): string
    {
        $rank = ['ok' => 0, 'off' => 1, 'warn' => 2, 'critical' => 3];
        $worst = 'ok';
        foreach ($this->checks() as $c) {
            if (($rank[$c['status']] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $c['status'];
            }
        }

        return $worst;
    }

    private function scheduler(): array
    {
        try {
            $last = Heartbeat::last('scheduler');
            if (! $last) {
                return $this->row('scheduler', 'Scheduler (cron)', 'warn', 'Operations',
                    'No heartbeat recorded yet — give it a minute after deploy, or the schedule:run cron is not installed.');
            }
            $age = (int) $last->diffInMinutes(now());
            if ($age > 5) {
                return $this->row('scheduler', 'Scheduler (cron)', 'critical', 'Operations',
                    "Last ran {$age} min ago — the schedule:run cron is NOT running. Auto-deploy, calendar sync, reminders, the watchdog and backups are all stopped.");
            }
            if ($age > 2) {
                return $this->row('scheduler', 'Scheduler (cron)', 'warn', 'Operations',
                    "Last ran {$age} min ago — a little behind.");
            }

            return $this->row('scheduler', 'Scheduler (cron)', 'ok', 'Operations',
                'Running — background jobs are alive (last beat under 2 min ago).');
        } catch (\Throwable $e) {
            return $this->row('scheduler', 'Scheduler (cron)', 'warn', 'Operations', 'Could not read the heartbeat.');
        }
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->row('database', 'Database', 'ok', 'Operations', 'Connected and responding.');
        } catch (\Throwable $e) {
            return $this->row('database', 'Database', 'critical', 'Operations', 'The database is not responding.');
        }
    }

    private function calendarSync(): array
    {
        try {
            $failed = CalendarEvent::where('sync_status', 'failed')->count();
            $stale = app(CalendarHealth::class)->isStale();

            if (! app(GoogleCalendarService::class)->configured()) {
                return $this->row('calendar_sync', 'Calendar sync', 'off', 'Operations', 'Not connected yet — bookings are kept and will sync once Google Calendar is connected.');
            }
            if ($failed > 0) {
                return $this->row('calendar_sync', 'Calendar sync', 'warn', 'Operations', "{$failed} booking event(s) failed to sync — they retry automatically every 5 min.");
            }
            if ($stale) {
                return $this->row('calendar_sync', 'Calendar sync', 'warn', 'Operations', 'The calendar mirror is behind — figures may be out of date until it catches up.');
            }

            return $this->row('calendar_sync', 'Calendar sync', 'ok', 'Operations', 'Up to date — bookings are syncing to the calendar.');
        } catch (\Throwable $e) {
            return $this->row('calendar_sync', 'Calendar sync', 'warn', 'Operations', 'Could not read calendar sync status.');
        }
    }

    private function backups(): array
    {
        try {
            $dir = storage_path('app/backups');
            $files = is_dir($dir) ? glob($dir.'/*') : [];
            if (empty($files)) {
                return $this->row('backups', 'Database backups', 'warn', 'Safety', 'No backup found yet — the hourly backup runs via the scheduler.');
            }
            $newest = max(array_map('filemtime', $files));
            $age = Carbon::createFromTimestamp($newest);
            $hours = (int) $age->diffInHours(now());
            $count = count($files);
            if ($hours > 26) {
                return $this->row('backups', 'Database backups', 'warn', 'Safety', "Newest backup is {$hours}h old ({$count} kept) — the hourly backup may not be running.");
            }

            return $this->row('backups', 'Database backups', 'ok', 'Safety', "Latest {$age->diffForHumans()} · {$count} snapshots kept (newest 72 ≈ 3 days).");
        } catch (\Throwable $e) {
            return $this->row('backups', 'Database backups', 'warn', 'Safety', 'Could not read the backups folder.');
        }
    }

    private function failedMessages(): array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('messages')) {
                return $this->row('messages', 'Office messages', 'ok', 'Operations', 'No message queue.');
            }
            $failed = DB::table('messages')->where('status', 'failed')->count();
            if ($failed > 0) {
                return $this->row('messages', 'Office messages', 'warn', 'Operations', "{$failed} message(s) failed to send — check the booking they belong to.");
            }

            return $this->row('messages', 'Office messages', 'ok', 'Operations', 'No failed messages.');
        } catch (\Throwable $e) {
            return $this->row('messages', 'Office messages', 'ok', 'Operations', 'No failed messages.');
        }
    }

    /**
     * A configured/not-configured connectivity row (keys present = connected).
     * A failing probe is treated as "off", never fatal.
     */
    private function connectivity(string $key, string $label, callable $isConfigured, string $okDetail, string $offDetail): array
    {
        try {
            return (bool) $isConfigured()
                ? $this->row($key, $label, 'ok', 'Connections', 'Connected — '.$okDetail)
                : $this->row($key, $label, 'off', 'Connections', 'Not connected. '.$offDetail);
        } catch (\Throwable $e) {
            return $this->row($key, $label, 'off', 'Connections', 'Not connected. '.$offDetail);
        }
    }

    /** @return array{key:string,label:string,status:string,detail:string,group:string} */
    private function row(string $key, string $label, string $status, string $group, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail', 'group');
    }
}
