<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissedCall extends Model
{
    protected $fillable = [
        'from_number', 'customer_id', 'received_at',
        'auto_response_sent', 'auto_response_sent_at', 'called_back',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'auto_response_sent' => 'boolean',
            'auto_response_sent_at' => 'datetime',
            'called_back' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
