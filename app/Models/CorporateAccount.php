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
        'name', 'company_number', 'slug', 'account_code', 'billing_email', 'phone', 'billing_address',
        'vat_number', 'cost_code_required', 'payment_terms_days', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'cost_code_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The Review page caches a name→account lookup; bust it when a business
        // is added/renamed/removed so grouping picks the change up at once.
        $forget = fn () => \Illuminate\Support\Facades\Cache::forget('corporate_name_map');
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * A professional, randomised account number — 7 characters from an
     * unambiguous alphabet (no O/0/I/1/L so it's never misread on an invoice or
     * over the phone), e.g. "K7P4Q2M". Guaranteed unique across all accounts.
     */
    public static function generateAccountCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::withTrashed()->where('account_code', $code)->exists());

        return $code;
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
