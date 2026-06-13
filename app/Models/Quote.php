<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quote extends Model
{
    protected $fillable = [
        'reference', 'customer_id', 'vehicle_type_id', 'pickup_address', 'destination_address',
        'distance_miles', 'duration_minutes', 'pickup_at', 'price', 'ai_generated',
        'breakdown', 'converted_booking_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'pickup_at' => 'datetime',
            'expires_at' => 'datetime',
            'price' => 'decimal:2',
            'distance_miles' => 'decimal:2',
            'ai_generated' => 'boolean',
            'breakdown' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
