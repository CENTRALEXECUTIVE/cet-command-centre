<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id', 'callsign', 'nickname', 'is_third_party', 'phv_badge_number', 'phv_badge_expiry',
        'dbs_status', 'dbs_issue_date', 'dbs_expiry', 'driving_licence_number',
        'driving_licence_expiry', 'default_vehicle_id', 'is_available', 'available_days',
        'availability_note', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_third_party' => 'boolean',
            'is_available' => 'boolean',
            'available_days' => 'array',
            'phv_badge_expiry' => 'date',
            'dbs_issue_date' => 'date',
            'dbs_expiry' => 'date',
            'driving_licence_expiry' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'default_vehicle_id');
    }

    /** Weekday numbers (0=Sun..6=Sat) this driver normally works. Empty = not set. */
    public function availableWeekdays(): array
    {
        return array_map('intval', $this->available_days ?? []);
    }

    /**
     * Is the driver normally available on this date's weekday? True when no weekly
     * pattern is set (unknown ≠ unavailable), so this only ever flags a KNOWN day off.
     */
    public function isAvailableOn(\Illuminate\Support\Carbon $date): bool
    {
        $days = $this->availableWeekdays();

        return empty($days) || in_array((int) $date->dayOfWeek, $days, true);
    }

    /** Short "Mon, Tue, Fri" summary of the weekly pattern, or null when unset. */
    public function availabilityLabel(): ?string
    {
        $days = $this->availableWeekdays();
        if (empty($days)) {
            return null;
        }
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        sort($days);

        return implode(', ', array_map(fn ($d) => $names[$d] ?? '', $days));
    }
}
