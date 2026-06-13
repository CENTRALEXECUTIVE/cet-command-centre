<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_type_id', 'registration', 'make', 'model', 'colour', 'year',
        'mot_expiry', 'insurance_expiry', 'phv_licence_expiry', 'compliance_test_date',
        'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'mot_expiry' => 'date',
            'insurance_expiry' => 'date',
            'phv_licence_expiry' => 'date',
            'compliance_test_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
