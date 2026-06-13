<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RotationState extends Model
{
    protected $fillable = [
        'airport_id', 'vehicle_type_id', 'next_driver_id', 'last_advanced_at',
    ];

    protected function casts(): array
    {
        return ['last_advanced_at' => 'datetime'];
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function nextDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'next_driver_id');
    }
}
