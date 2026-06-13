<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingStop extends Model
{
    protected $fillable = ['booking_id', 'sequence', 'address', 'postcode'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
