<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    protected $fillable = [
        'booking_id', 'calendar_id', 'google_event_id', 'title', 'location',
        'start_at', 'end_at', 'timezone', 'payment_emoji', 'notifications',
        'sync_status', 'synced_at', 'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'synced_at' => 'datetime',
            'notifications' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
