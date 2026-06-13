<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleType extends Model
{
    protected $fillable = [
        'name', 'slug', 'passenger_capacity', 'luggage_capacity',
        'affects_rotation', 'uses_third_party_driver', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'affects_rotation' => 'boolean',
            'uses_third_party_driver' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
