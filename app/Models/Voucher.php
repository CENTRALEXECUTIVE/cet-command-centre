<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A discount code for the public booking system (mirrors ETO's Voucher
 * Discounts): a percentage or fixed amount off the fare, optionally capped to a
 * number of uses and a valid-date window.
 */
class Voucher extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'max_uses', 'used_count',
        'valid_from', 'valid_to', 'comment', 'is_active',
    ];

    protected $casts = [
        'value' => 'float',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_active' => 'boolean',
    ];

    /** Look up a code case-insensitively (customers type them any which way). */
    public static function findByCode(?string $code): ?self
    {
        $code = trim((string) $code);

        return $code === '' ? null : static::whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])->first();
    }

    /** Can this voucher be used right now (active, in date, uses left)? */
    public function isRedeemable(?Carbon $at = null): bool
    {
        $at ??= now();

        if (! $this->is_active) {
            return false;
        }
        if ($this->valid_from && $at->lt($this->valid_from)) {
            return false;
        }
        if ($this->valid_to && $at->gt($this->valid_to)) {
            return false;
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /** The discount this voucher gives on an amount (never more than the amount). */
    public function discountOn(float $amount): float
    {
        $amount = max(0.0, $amount);
        $off = $this->type === 'percent'
            ? $amount * ($this->value / 100)
            : $this->value;

        return round(min($amount, max(0.0, $off)), 2);
    }

    /** Consume one use (called once payment succeeds). */
    public function redeem(): void
    {
        $this->increment('used_count');
    }

    /** A short human label, e.g. "20% off" or "£15 off". */
    public function label(): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim(number_format($this->value, 2), '0'), '.').'% off'
            : '£'.number_format($this->value, 2).' off';
    }
}
