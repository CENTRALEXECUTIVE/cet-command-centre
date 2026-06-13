<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Airport extends Model
{
    protected $fillable = ['code', 'name', 'is_general_pool', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_general_pool' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function rotationStates(): HasMany
    {
        return $this->hasMany(RotationState::class);
    }
}
