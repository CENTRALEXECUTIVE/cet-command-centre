<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Records "this ran" timestamps so the System Health page can tell whether the
 * scheduler (and the key background jobs it drives) are actually alive. The
 * scheduler cron underpins auto-deploy, calendar sync, reminders, the watchdog,
 * invoicing and backups — if it stops, everything silently dies. The global
 * 'scheduler' beat is stamped every minute; individual jobs stamp their own name
 * when they run. Everything here is crash-safe: a failure never takes a page down.
 */
class Heartbeat
{
    /** Record that something ran just now. Never throws. */
    public static function stamp(string $name = 'scheduler'): void
    {
        try {
            Setting::set("heartbeat.$name", now()->toIso8601String(), 'string', 'system');
        } catch (\Throwable $e) {
            // A heartbeat must never break the job it's reporting on.
        }
    }

    /** When this name last ran, or null if never / unreadable. */
    public static function last(string $name = 'scheduler'): ?Carbon
    {
        try {
            $v = Setting::get("heartbeat.$name");

            return $v ? Carbon::parse($v) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Whole minutes since this name last ran, or null if never. */
    public static function ageMinutes(string $name = 'scheduler'): ?int
    {
        $last = self::last($name);

        return $last ? (int) $last->diffInMinutes(now()) : null;
    }

    /**
     * Is the scheduler stale? True when the global beat hasn't been stamped within
     * the grace window (default 5 min) — meaning the `schedule:run` cron is almost
     * certainly not running. Never throws.
     */
    public static function schedulerStale(int $graceMinutes = 5): bool
    {
        $age = self::ageMinutes('scheduler');

        // Null = never stamped yet. Treat a brand-new install as "not stale" so we
        // don't cry wolf before the first minute tick; once it has ever stamped,
        // a missing recent beat is a real problem.
        if ($age === null) {
            return self::last('scheduler') === null ? false : true;
        }

        return $age > $graceMinutes;
    }
}
