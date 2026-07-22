<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single driver gratuity — cash logged by the office, or a card tip taken
 * through Square. Its own table so a sync rewriting a booking's meta can never
 * wipe it.
 */
class BookingTip extends Model
{
    protected $fillable = [
        'booking_id', 'amount', 'method', 'source', 'square_payment_id', 'note', 'logged_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
