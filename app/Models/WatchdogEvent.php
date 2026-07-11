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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
