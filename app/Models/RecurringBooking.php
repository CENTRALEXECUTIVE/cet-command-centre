<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A standing booking template that generates a real booking for each upcoming
 * occurrence (e.g. "every Monday 08:00, Sheffield → Manchester Airport").
 */
class RecurringBooking extends Model
{
    protected $fillable = [
        'customer_id', 'vehicle_type_id', 'frequency', 'weekday', 'pickup_time',
        'pickup_address', 'pickup_postcode', 'destination_address', 'destination_postcode',
        'passengers', 'payment_method', 'notes', 'lead_days', 'last_generated_on',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'passengers' => 'integer',
            'lead_days' => 'integer',
            'last_generated_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    /** True when an occurrence falls on $date for this template's frequency. */
    public function occursOn(Carbon $date): bool
    {
        return match ($this->frequency) {
            'daily' => true,
            'weekdays' => ! $date->isWeekend(),
            'weekly' => $this->weekday !== null && (int) $date->dayOfWeek === (int) $this->weekday,
            default => false,
        };
    }

    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'daily' => 'Every day',
            'weekdays' => 'Weekdays (Mon–Fri)',
            'weekly' => 'Every '.Carbon::create()->startOfWeek(Carbon::SUNDAY)->addDays((int) $this->weekday)->format('l'),
            default => ucfirst($this->frequency),
        };
    }
}
