<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'date', 'campaign', 'spend', 'revenue', 'conversions', 'jobs',
        'clicks', 'impressions', 'roas', 'budget_alert_triggered',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend' => 'decimal:2',
            'revenue' => 'decimal:2',
            'roas' => 'decimal:2',
            'budget_alert_triggered' => 'boolean',
        ];
    }
}
