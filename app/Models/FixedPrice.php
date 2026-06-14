<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedPrice extends Model
{
    protected $fillable = [
        'pricing_zone_id', 'destination', 'destination_slug',
        'vehicle_type_id', 'price', 'deposit', 'both_ways', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'both_ways' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function pricingZone(): BelongsTo
    {
        return $this->belongsTo(PricingZone::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
