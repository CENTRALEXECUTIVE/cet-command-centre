<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Keyword extends Model
{
    use HasFactory;

    protected $fillable = [
        'keyword', 'match_type', 'campaign', 'status', 'max_cpc',
        'clicks', 'impressions', 'conversions', 'cost', 'avg_position',
    ];

    protected function casts(): array
    {
        return [
            'max_cpc' => 'decimal:2',
            'cost' => 'decimal:2',
            'avg_position' => 'decimal:1',
        ];
    }

    /** Cost per acquisition (cost / conversions). */
    public function getCpaAttribute(): ?float
    {
        return $this->conversions > 0 ? round($this->cost / $this->conversions, 2) : null;
    }

    /** Click-through rate as a percentage. */
    public function getCtrAttribute(): ?float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 1) : null;
    }
}
