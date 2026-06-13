<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'corporate_account_id', 'cost_code', 'corporate_reference',
        'vehicle_type_id', 'airport_id', 'journey_type', 'is_return_leg', 'linked_booking_id',
        'pickup_at', 'pickup_address', 'pickup_postcode', 'destination_address', 'destination_postcode',
        'flight_number', 'passengers', 'luggage', 'special_requests',
        'status', 'driver_id', 'vehicle_id', 'affected_rotation',
        'quoted_price', 'final_price', 'payment_method', 'payment_status',
        'source', 'created_by', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'pickup_at' => 'datetime',
            'is_return_leg' => 'boolean',
            'affected_rotation' => 'boolean',
            'passengers' => 'integer',
            'luggage' => 'integer',
            'quoted_price' => 'decimal:2',
            'final_price' => 'decimal:2',
            'status' => BookingStatus::class,
            'payment_method' => PaymentMethod::class,
            'meta' => 'array',
        ];
    }

    // ----- Relationships -------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function linkedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'linked_booking_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(BookingStop::class)->orderBy('sequence');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function calendarEvent(): HasOne
    {
        return $this->hasOne(CalendarEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function trackingLink(): HasOne
    {
        return $this->hasOne(TrackingLink::class);
    }

    public function flightMonitor(): HasOne
    {
        return $this->hasOne(FlightMonitor::class);
    }

    // ----- Scopes --------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BookingStatus::Accepted->value,
            BookingStatus::EnRoute->value,
            BookingStatus::Collected->value,
        ]);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    // ----- Helpers -------------------------------------------------------

    /** Generate the next unique booking reference, e.g. CET-2A4F9C. */
    public static function generateReference(): string
    {
        do {
            $reference = 'CET-'.strtoupper(bin2hex(random_bytes(3)));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }
}
