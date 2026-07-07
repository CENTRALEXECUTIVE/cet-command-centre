<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RotationLog extends Model
{
    protected $fillable = [
        'airport_id', 'vehicle_type_id', 'booking_id',
        'from_driver_id', 'to_driver_id', 'reason',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function fromDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_driver_id');
    }

    public function toDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_driver_id');
    }
}
