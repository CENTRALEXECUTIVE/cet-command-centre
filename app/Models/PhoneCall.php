<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call answered by the AI phone agent (see App\Services\Voice\PhoneAgent). */
class PhoneCall extends Model
{
    protected $fillable = [
        'call_sid', 'from_number', 'status', 'transcript', 'booking_fields', 'quotes',
        'message', 'summary', 'needs_action', 'booking_id', 'reviewed_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'booking_fields' => 'array',
            'quotes' => 'array',
            'needs_action' => 'boolean',
            'reviewed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Append one line to the transcript: role = caller | agent | system. */
    public function say(string $role, string $text): void
    {
        $t = $this->transcript ?? [];
        $t[] = ['role' => $role, 'text' => $text, 'at' => now()->toIso8601String()];
        $this->transcript = $t;
    }

    public function minutes(): ?int
    {
        return $this->ended_at ? max(1, (int) ceil($this->created_at->diffInSeconds($this->ended_at) / 60)) : null;
    }
}
