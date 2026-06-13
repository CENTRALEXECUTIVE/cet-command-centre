<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceItem extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'item_type', 'reference',
        'issued_on', 'due_on', 'status', 'reminder_sent_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'reminder_sent_at' => 'datetime',
        ];
    }
}
