<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consent extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'email', 'type', 'granted',
        'version', 'ip_address', 'granted_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }
}
