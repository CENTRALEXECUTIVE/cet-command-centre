<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CorporateAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'account_code', 'billing_email', 'phone', 'billing_address',
        'vat_number', 'cost_code_required', 'payment_terms_days', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'cost_code_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CorporateContact::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('can_view_all_account_bookings')
            ->withTimestamps();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
