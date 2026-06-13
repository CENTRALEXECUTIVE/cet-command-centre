<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'from_status', 'to_status', 'changed_by',
        'gps_latitude', 'gps_longitude', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'gps_latitude' => 'decimal:7',
            'gps_longitude' => 'decimal:7',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
