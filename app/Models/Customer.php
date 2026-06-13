<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'corporate_account_id', 'preferred_pickup_address',
        'preferred_vehicle_type_id', 'marketing_consent', 'marketing_consent_at',
        'is_vip', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'is_vip' => 'boolean',
        ];
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
