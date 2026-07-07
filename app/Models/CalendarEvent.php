<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    protected $fillable = [
        'booking_id', 'calendar_id', 'google_event_id', 'title', 'location', 'description',
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

    /**
     * Read a single "• *Label:* value" field out of the event description (the
     * Booking Confirmation block), e.g. descriptionValue('Luggage') →
     * "2 Suitcases + 1 Hand Luggage". The calendar is the source of truth, so
     * the app mirrors these verbatim. Null when the field isn't present.
     */
    public function descriptionValue(string $label): ?string
    {
        $pattern = '/'.preg_quote($label, '/').':\*?\s*([^\n]+)/i';
        if (! preg_match($pattern, (string) $this->description, $m)) {
            return null;
        }

        $value = trim(rtrim(trim($m[1]), '*'));

        return $value !== '' ? $value : null;
    }

    /**
     * The authoritative pickup time for this event: the time PRINTED in the
     * description ("Date & Time") when present, else the event slot. The printed
     * time is ETO's real local time and is trusted over the slot, because an ICS
     * import once mis-read UTC-tagged slots an hour late while the printed text
     * stayed correct — so where they disagree, the description wins.
     */
    public function calendarPickupAt(): ?\Illuminate\Support\Carbon
    {
        return $this->descriptionPickupAt() ?? $this->start_at;
    }

    /**
     * The pickup date+time printed INSIDE the description's Booking Confirmation
     * block ("• *Date & Time:* 15/07/2026 – 06:45"), or null when it isn't
     * present/parseable. Lets us confirm the printed time still agrees with the
     * event's actual slot and the booking.
     */
    public function descriptionPickupAt(): ?\Illuminate\Support\Carbon
    {
        if (! preg_match('#Date\s*&\s*Time:\*?\s*(\d{1,2}/\d{1,2}/\d{4})\s*[–—-]\s*(\d{1,2}:\d{2})#u', (string) $this->description, $m)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::createFromFormat('d/m/Y H:i', $m[1].' '.$m[2], config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
