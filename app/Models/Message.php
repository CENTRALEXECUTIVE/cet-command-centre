<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'booking_id', 'customer_id', 'channel', 'direction', 'type', 'to_address',
        'body', 'status', 'provider_message_id', 'scheduled_for', 'sent_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Is this one of the scheduled pickup reminders? */
    public function isReminder(): bool
    {
        return in_array($this->type, ['reminder_24h', 'reminder_2h'], true);
    }

    /**
     * Should the manual "Send on WhatsApp" prompt be offered yet? A scheduled
     * reminder stays hidden until its (windowed) send time arrives, so the
     * office is never nudged to message a customer early or overnight. Anything
     * else (confirmation, driver details, one-off messages) is always sendable.
     */
    public function isReadyToSend(): bool
    {
        return ! ($this->isReminder() && $this->scheduled_for && $this->scheduled_for->isFuture());
    }

    /** The recipient number in international format (44…) for wa.me links. */
    public function intlPhone(): ?string
    {
        $number = preg_replace('/[^0-9+]/', '', (string) $this->to_address);
        if (blank($number)) {
            return null;
        }
        if (str_starts_with($number, '+')) {
            return substr($number, 1);
        }
        if (str_starts_with($number, '0')) {
            return '44'.substr($number, 1);
        }

        return $number;
    }

    /**
     * A click-to-send WhatsApp link: opens WhatsApp (app or web) with the
     * recipient AND this message's text pre-filled, ready for the operator to
     * send by hand — no API, no cost. Null if there's no usable phone number.
     */
    public function whatsAppLink(): ?string
    {
        $phone = $this->intlPhone();
        if (! $phone) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode((string) $this->body);
    }
}
