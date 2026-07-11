<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_super_admin',
        'phone',
        'is_active',
        'last_login_at',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'role' => UserRole::class,
            'notification_preferences' => 'array',
        ];
    }

    // ----- Admin alert preferences ----------------------------------------

    /** The admin-alert event types a preference toggle exists for. */
    public const ALERT_TYPES = [
        'unacted' => 'Driver ignoring nudges',
        'unallocated' => 'Job unallocated near pickup',
        'gps_lost' => 'GPS lost mid-job',
        'no_show_cancel' => 'No-show / cancellation',
        'calendar_import' => 'New booking from the calendar',
    ];

    /** Preference defaults: every alert on, critical-only off, chime off. */
    public function alertPreferences(): array
    {
        return array_merge(
            array_fill_keys(array_keys(self::ALERT_TYPES), true),
            ['critical_only' => false, 'chime' => false],
            $this->notification_preferences ?? [],
        );
    }

    /** Should this admin receive a push for this event type at this severity? */
    public function wantsAlert(string $type, string $severity = 'info'): bool
    {
        if (! $this->is_active || ! $this->isAdmin()) {
            return false;
        }

        $prefs = $this->alertPreferences();

        if (($prefs['critical_only'] ?? false) && $severity !== 'critical') {
            return false;
        }

        return (bool) ($prefs[$type] ?? true);
    }

    // ----- Relationships -------------------------------------------------

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function driverDocuments(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    /** Devices this user has enabled push notifications on. */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function corporateAccounts(): BelongsToMany
    {
        return $this->belongsToMany(CorporateAccount::class)
            ->withPivot('can_view_all_account_bookings')
            ->withTimestamps();
    }

    /** Jobs assigned to this user as the driver. */
    public function driverBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'driver_id');
    }

    public function createdBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'created_by');
    }

    // ----- Role helpers --------------------------------------------------

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** A super admin can manage all users, including other admins. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin && $this->role === UserRole::Admin;
    }

    public function isDriver(): bool
    {
        return $this->role === UserRole::Driver;
    }

    public function isCorporateClient(): bool
    {
        return $this->role === UserRole::CorporateClient;
    }
}
