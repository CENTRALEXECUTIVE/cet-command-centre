<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitingListEntry extends Model
{
    protected $fillable = [
        'customer_id', 'vehicle_type_id', 'desired_date', 'desired_time_window',
        'pickup_address', 'destination_address', 'status', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'desired_date' => 'date',
            'notified_at' => 'datetime',
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
