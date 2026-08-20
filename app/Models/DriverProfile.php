<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id', 'callsign', 'nickname', 'is_third_party', 'phv_badge_number', 'phv_badge_expiry',
        'dbs_status', 'dbs_issue_date', 'dbs_expiry', 'driving_licence_number',
        'driving_licence_expiry', 'default_vehicle_id', 'is_available', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_third_party' => 'boolean',
            'is_available' => 'boolean',
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
}
