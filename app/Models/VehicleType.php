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

    /**
     * Marketing photo for the public booking page, if one has been dropped in at
     * public/images/fleet/{slug}.{webp|png|jpg}. Returns null when there isn't
     * one yet, so the page falls back to a clean car silhouette.
     */
    public function photoUrl(): ?string
    {
        foreach (['webp', 'png', 'jpg', 'jpeg'] as $ext) {
            $rel = 'images/fleet/'.$this->slug.'.'.$ext;
            $path = public_path($rel);
            if (is_file($path)) {
                // Cache-bust on the file's mtime so a replaced photo shows at once.
                return asset($rel).'?v='.@filemtime($path);
            }
        }

        return null;
    }

    /** A van/minibus-shaped vehicle (for the silhouette + ribbons pricing). */
    public function isLargeVehicle(): bool
    {
        return str_starts_with((string) $this->slug, 'minibus') || $this->slug === 'v-class';
    }

    /** Whether this class is quote-only (Rolls/Luxury) rather than instant-priced. */
    public function isQuoteOnly(): bool
    {
        return $this->slug === 'rolls-royce-ghost' || str_contains(strtolower($this->name), 'luxury');
    }

    /** Short marketing subtitle under the name on the booking cards. */
    public function tagline(): ?string
    {
        return match ($this->slug) {
            'executive' => 'Mercedes E/S-Class',
            'estate' => 'Mercedes E-Class Estate',
            'minibus-8' => '8 seats',
            'minibus-8-xl' => 'Full capacity',
            'v-class' => 'Mercedes V-Class',
            'rolls-royce-ghost' => 'Rolls-Royce',
            default => null,
        };
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
