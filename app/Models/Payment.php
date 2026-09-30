<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'booking_id', 'invoice_id', 'method', 'amount', 'status',
        'tide_payment_link', 'tide_reference', 'paid_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** ETO-style transaction name (e.g. "Full amount", "Deposit", "Balance"). */
    public function name(): string
    {
        return trim((string) ($this->meta['name'] ?? '')) ?: 'Full amount';
    }

    /** Any card/processing charge added to this transaction (£). */
    public function charge(): float
    {
        return (float) ($this->meta['charge'] ?? 0);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** A human label for the payment method. */
    public function methodLabel(): string
    {
        return match ($this->method) {
            'card' => 'Card / Square',
            'cash' => 'Cash',
            'account' => 'Account',
            default => ucfirst((string) $this->method),
        };
    }

    /** A short label for the status, matching the badge classes elsewhere. */
    public function statusLabel(): string
    {
        return ucfirst(str_replace('_', ' ', (string) $this->status));
    }
}
