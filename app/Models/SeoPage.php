<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'path', 'title', 'meta_description', 'target_keyword',
        'current_rank', 'monthly_searches', 'notes',
    ];
}
