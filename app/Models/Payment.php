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

    protected static function booted(): void
    {
        // The first ledger line on a booking already paid ONLINE (recorded on the
        // booking, not as a line): put that payment in the ledger first. Once the
        // ledger has rows it's the only source of "paid", so without this a £45
        // balance line on a £55-paid booking would read £45 paid, not £100.
        static::creating(function (Payment $payment) {
            if (! empty($payment->meta['online_seed']) || ! $payment->booking_id) {
                return;
            }
            $booking = Booking::find($payment->booking_id);
            if (! $booking || $booking->payments()->exists()) {
                return;
            }
            // Paid but NOT online (an ETO/calendar import flagged paid, or marked paid
            // by hand): record that the fare was settled before the ledger starts, so
            // adding e.g. a soiling charge never turns a paid journey into "£0 paid,
            // fare due" (CET-583A55, Oct 2026).
            if ($booking->onlinePaidTotal() <= 0) {
                $fare = $booking->amountPayable();
                // Only when the new line is an UNPAID charge/request: a first line
                // recorded as PAID is the office logging the fare itself — seeding
                // too would count the fare twice.
                if ($booking->payment_status === 'paid' && $payment->status !== 'paid' && $fare !== null && $fare > 0) {
                    static::create([
                        'booking_id' => $booking->id,
                        'method' => $booking->payment_method?->value ?? 'card',
                        'amount' => round($fare, 2),
                        'status' => 'paid',
                        'paid_at' => $booking->updated_at ?? now(),
                        'meta' => ['name' => 'Fare (paid before)', 'online_seed' => true],
                    ]);
                }

                return;
            }
            $amount = round(min($booking->onlinePaidTotal(), $booking->amountPayable() ?? $booking->onlinePaidTotal()), 2);
            $sp = $booking->meta['square_payment'] ?? [];
            static::create([
                'booking_id' => $booking->id,
                'method' => 'card',
                'amount' => $amount,
                'status' => 'paid',
                'paid_at' => isset($sp['at']) ? \Illuminate\Support\Carbon::parse($sp['at']) : now(),
                'tide_reference' => $sp['id'] ?? null,
                'meta' => ['name' => 'Paid online', 'online_seed' => true],
            ]);
        });
    }

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
