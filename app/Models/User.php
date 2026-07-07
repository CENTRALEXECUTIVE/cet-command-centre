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
        ];
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
