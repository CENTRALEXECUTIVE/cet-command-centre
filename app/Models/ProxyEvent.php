<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for number masking: session opens/closes and every call/message
 * event Twilio reports. Purged on the GPS retention schedule (90 days).
 */
class ProxyEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'proxy_session_id', 'booking_id', 'event_type', 'payload', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProxySession::class, 'proxy_session_id');
    }
}
