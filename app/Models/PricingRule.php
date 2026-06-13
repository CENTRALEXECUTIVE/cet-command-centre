<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    protected $fillable = [
        'vehicle_type_id', 'base_fare', 'per_mile', 'per_minute', 'minimum_fare',
        'airport_surcharge', 'night_multiplier', 'night_starts_at', 'night_ends_at',
        'peak_multiplier', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_fare' => 'decimal:2',
            'per_mile' => 'decimal:2',
            'per_minute' => 'decimal:2',
            'minimum_fare' => 'decimal:2',
            'airport_surcharge' => 'decimal:2',
            'night_multiplier' => 'decimal:2',
            'peak_multiplier' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
