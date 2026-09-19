<?php

namespace App\Services\Calendar;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Tells the app how fresh its calendar mirror is. The scheduled
 * cet:calendar-refresh stamps `calendar_last_sync_ok` every time it genuinely
 * reaches Google; if that stops happening (DNS down, credentials removed,
 * scheduler not running) the app can WARN that its data may be stale instead of
 * silently showing out-of-date money — the exact failure that hit on 19 Sep 2026.
 *
 * Every read is crash-safe: a missing settings table or unparsable value returns
 * "unknown", never an exception, so it can be called from any layout.
 */
class CalendarHealth
{
    /** Sync runs every 5 min; flag as stale once it's this far behind. */
    public const STALE_AFTER_MINUTES = 20;

    public function lastSyncAt(): ?Carbon
    {
        try {
            $value = Setting::get('calendar_last_sync_ok');
        } catch (\Throwable $e) {
            return null;
        }
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function minutesSinceSync(): ?int
    {
        $at = $this->lastSyncAt();

        return $at ? (int) $at->diffInMinutes(now()) : null;
    }

    /**
     * Stale ONLY when a sync has succeeded before (so a never-configured install
     * never nags) AND the last success is older than the threshold.
     */
    public function isStale(int $thresholdMinutes = self::STALE_AFTER_MINUTES): bool
    {
        $mins = $this->minutesSinceSync();

        return $mins !== null && $mins >= $thresholdMinutes;
    }

    /** Human age of the last successful sync, e.g. "3 hours", or null if never. */
    public function ageForHumans(): ?string
    {
        $at = $this->lastSyncAt();

        return $at ? $at->diffForHumans(null, true) : null;
    }
}
