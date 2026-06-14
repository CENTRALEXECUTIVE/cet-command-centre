<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingZone extends Model
{
    protected $fillable = ['name', 'slug', 'postcode_prefixes', 'is_active'];

    protected function casts(): array
    {
        return [
            'postcode_prefixes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function fixedPrices(): HasMany
    {
        return $this->hasMany(FixedPrice::class);
    }
}
