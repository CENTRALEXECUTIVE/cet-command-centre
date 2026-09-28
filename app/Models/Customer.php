<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Customer extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'corporate_account_id', 'preferred_pickup_address',
        'preferred_vehicle_type_id', 'marketing_consent', 'marketing_consent_at',
        'is_vip', 'notes',
    ];

    protected $hidden = [
        'password', 'password_reset_token',
    ];

    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'is_vip' => 'boolean',
            'password' => 'hashed',
            'password_reset_expires_at' => 'datetime',
        ];
    }

    // ----- Self-service password login (My Account widget only) -------------
    // Customers can OPTIONALLY set a password to sign into the "My Account"
    // widget. This never makes them a system user — it's a lightweight, isolated
    // login layered on the customer record, kept well away from staff auth.

    /** Is the password-login schema present? Cached so it's crash-safe pre-migration. */
    public static function passwordLoginAvailable(): bool
    {
        static $has = null;

        if ($has === null) {
            try {
                $has = Schema::hasColumn('customers', 'password');
            } catch (\Throwable) {
                $has = false;
            }
        }

        return $has;
    }

    /** Has this customer set a login password? */
    public function hasPassword(): bool
    {
        return self::passwordLoginAvailable() && filled($this->password);
    }

    /** Set (hash) a login password. */
    public function setLoginPassword(string $plain): void
    {
        $this->forceFill([
            'password' => $plain,
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
        ])->save();
    }

    /** Verify a plaintext password against the stored hash. */
    public function checkPassword(string $plain): bool
    {
        return $this->hasPassword() && Hash::check($plain, $this->password);
    }

    /**
     * Start a forgot-password flow: store a hashed, time-limited reset token and
     * return the RAW token to email. Returns null if password login isn't available.
     */
    public function startPasswordReset(int $ttlMinutes = 60): ?string
    {
        if (! self::passwordLoginAvailable()) {
            return null;
        }

        $raw = Str::random(48);
        $this->forceFill([
            'password_reset_token' => hash('sha256', $raw),
            'password_reset_expires_at' => now()->addMinutes($ttlMinutes),
        ])->save();

        return $raw;
    }

    /** Does this raw token match an unexpired reset request? */
    public function resetTokenValid(string $raw): bool
    {
        if (blank($this->password_reset_token) || ! $this->password_reset_expires_at) {
            return false;
        }
        if ($this->password_reset_expires_at instanceof Carbon && $this->password_reset_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->password_reset_token, hash('sha256', $raw));
    }

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }

    public function preferredVehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'preferred_vehicle_type_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
