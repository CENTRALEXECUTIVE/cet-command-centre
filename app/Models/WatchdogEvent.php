<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the control-tower log — everything the watchdog (and the status
 * engine / calendar import) thinks the office should be able to see on the
 * dashboard alerts feed. Critical rows glow until acknowledged. Pruned after
 * 30 days by cet:prune-gps.
 */
class WatchdogEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id', 'driver_id', 'event_type', 'severity',
        'title', 'body', 'occurred_at', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    /** Write a feed row. Never throws — the log must not break a workflow. */
    public static function log(
        string $type,
        string $title,
        string $severity = 'info',
        ?Booking $booking = null,
        ?int $driverId = null,
        ?string $body = null,
    ): ?self {
        try {
            return self::create([
                'booking_id' => $booking?->id,
                'driver_id' => $driverId ?? $booking?->driver_id,
                'event_type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            return null; // e.g. table not migrated yet — never break the caller
        }
    }

    public function scopeUnacknowledgedCritical(Builder $q): Builder
    {
        return $q->where('severity', 'critical')->whereNull('acknowledged_at');
    }

    /**
     * Unacknowledged-critical count for the nav badge — shown on EVERY admin
     * page, so it must survive the table not existing yet (deploys where the
     * operator hasn't run the migration).
     */
    public static function criticalCount(): int
    {
        try {
            return self::unacknowledgedCritical()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
