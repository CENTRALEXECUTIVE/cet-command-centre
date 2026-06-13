<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankHoliday extends Model
{
    protected $fillable = ['date', 'name', 'surcharge_percent'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'surcharge_percent' => 'decimal:2',
        ];
    }
}
