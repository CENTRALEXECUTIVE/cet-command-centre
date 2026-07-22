<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A masked customer↔driver conversation (Twilio Proxy session) for one
 * booking. masked_number is the line the DRIVER dials — the only customer
 * contact a driver ever sees. Closed on complete/cancel/reassign and by the
 * scheduled sweep once closes_at passes.
 */
class ProxySession extends Model
{
    protected $fillable = [
        'booking_id', 'twilio_session_sid', 'customer_participant_sid',
        'driver_participant_sid', 'driver_id', 'masked_number',
        'customer_masked_number', 'status', 'opened_at', 'closes_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProxyEvent::class);
    }
}
