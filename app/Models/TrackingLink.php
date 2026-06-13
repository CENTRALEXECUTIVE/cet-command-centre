<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingLink extends Model
{
    protected $fillable = [
        'booking_id', 'token', 'expires_at', 'view_count', 'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
