<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightMonitor extends Model
{
    protected $fillable = [
        'booking_id', 'flight_number', 'flight_date', 'scheduled_arrival', 'estimated_arrival',
        'status', 'delay_minutes', 'pickup_adjusted', 'customer_notified', 'last_checked_at', 'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'flight_date' => 'date',
            'scheduled_arrival' => 'datetime',
            'estimated_arrival' => 'datetime',
            'last_checked_at' => 'datetime',
            'pickup_adjusted' => 'boolean',
            'customer_notified' => 'boolean',
            'raw_response' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
