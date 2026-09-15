<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoverDriver extends Model
{
    protected $fillable = ['user_id', 'name', 'phone', 'vehicle_reg', 'vehicle', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** The login account this directory entry is attached to (if any). */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
