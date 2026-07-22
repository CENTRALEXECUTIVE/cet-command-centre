<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nudge push sent about a job (driver: "time to set off", "tap Arrived"…;
 * admin escalations in the same table). The watchdog reads this table before
 * sending anything — max 2 sends per nudge type per job, 5 minutes apart.
 */
class JobNudge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id', 'nudge_type', 'recipient_type', 'recipient_id', 'sent_at', 'channel',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
