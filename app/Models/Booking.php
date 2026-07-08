<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'corporate_account_id', 'cost_code', 'corporate_reference',
        'vehicle_type_id', 'airport_id', 'journey_type', 'is_return_leg', 'linked_booking_id',
        'pickup_at', 'pickup_address', 'pickup_postcode', 'destination_address', 'destination_postcode',
        'flight_number', 'passengers', 'luggage', 'special_requests',
        'status', 'driver_id', 'vehicle_id', 'affected_rotation',
        'quoted_price', 'final_price', 'payment_method', 'payment_status',
        'source', 'source_system', 'external_reference', 'created_by', 'meta',
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
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

    /**
     * Deep link that opens this flight on Flightradar24 (free, no API key) — the
     * live tracker the office already uses. Null when there's no flight number.
     */
    public static function flightRadarLink(?string $flightNumber): ?string
    {
        $fn = strtolower(preg_replace('/\s+/', '', (string) $flightNumber));

        return $fn !== '' ? 'https://www.flightradar24.com/data/flights/'.$fn : null;
    }

    /** Google live flight-status search (shows the status card) for a flight number. */
    public static function flightSearchLink(?string $flightNumber): ?string
    {
        $fn = strtoupper(preg_replace('/\s+/', '', (string) $flightNumber));

        return $fn !== '' ? 'https://www.google.com/search?q='.rawurlencode('flight '.$fn.' status') : null;
    }

    public function flightRadarUrl(): ?string
    {
        return self::flightRadarLink($this->flight_number);
    }

    public function flightSearchUrl(): ?string
    {
        return self::flightSearchLink($this->flight_number);
    }

    /**
     * The name to display for this job — the LEAD PASSENGER (who's travelling)
     * from meta['lead_name'] where set, else the customer/booker on the booking.
     * Keeps the dispatch board and calendar showing the same person.
     */
    public function displayName(): string
    {
        return $this->meta['lead_name'] ?? $this->customer?->name ?? 'Customer';
    }

    /**
     * Suitcase + hand-luggage counts, resolved from the most reliable source in
     * turn: the discrete meta counts (new bookings + the form), then the
     * descriptive "N Suitcases + N Hand Luggage" text that built the calendar
     * event — so a booking made before the counts were stored still shows the
     * exact split that's on its calendar. Returns [null, null] when unknown.
     *
     * @return array{0:?int,1:?int}
     */
    public function luggageCounts(): array
    {
        $suitcases = $this->meta['suitcases'] ?? null;
        $hand = $this->meta['hand_luggage'] ?? null;
        if ($suitcases !== null || $hand !== null) {
            return [(int) $suitcases, (int) $hand];
        }

        // Fall back to the descriptive luggage text mirrored onto the calendar,
        // e.g. "2 Suitcases + 1 Hand Luggage".
        $text = (string) ($this->meta['luggage_text'] ?? '');
        if ($text !== '' && strcasecmp(trim($text), 'None') !== 0) {
            preg_match('/(\d+)\s*suitcase/i', $text, $s);
            preg_match('/(\d+)\s*hand/i', $text, $h);
            if ($s || $h) {
                return [(int) ($s[1] ?? 0), (int) ($h[1] ?? 0)];
            }
        }

        return [null, null];
    }

    /**
     * Luggage shown as a suitcases + hand-luggage breakdown, e.g.
     * "2 suitcases · 1 hand luggage" — both counts matter to the driver and the
     * vehicle choice. Falls back to the combined "N bags" total only when no
     * breakdown is known anywhere.
     */
    public function luggageBreakdown(): string
    {
        // The calendar is the source of truth — mirror its Luggage line verbatim.
        if ($fromCalendar = $this->calendarEvent?->descriptionValue('Luggage')) {
            return $fromCalendar;
        }

        [$suitcases, $hand] = $this->luggageCounts();

        if ($suitcases !== null || $hand !== null) {
            $suitcases = (int) $suitcases;
            $hand = (int) $hand;

            return $suitcases.' suitcase'.($suitcases === 1 ? '' : 's')
                .' · '.$hand.' hand luggage';
        }

        $total = (int) $this->luggage;

        return $total > 0 ? $total.' bag'.($total === 1 ? '' : 's') : 'No luggage';
    }

    /** Compact luggage for the bookings table, e.g. "2 case · 1 hand" or "—". */
    public function luggageShort(): string
    {
        // Mirror the calendar's Luggage line when there's an event.
        if ($fromCalendar = $this->calendarEvent?->descriptionValue('Luggage')) {
            return $fromCalendar;
        }

        [$suitcases, $hand] = $this->luggageCounts();

        if ($suitcases !== null || $hand !== null) {
            $suitcases = (int) $suitcases;
            $hand = (int) $hand;

            return $suitcases.' case'.($suitcases === 1 ? '' : 's').' · '.$hand.' hand';
        }

        $total = (int) $this->luggage;

        return $total > 0 ? $total.' bag'.($total === 1 ? '' : 's') : '—';
    }

    /**
     * The passenger count to show — the calendar is the source of truth, so its
     * "Passengers" line wins where there's a linked event, falling back to the
     * booking's own column. Keeps the booking page from disagreeing with the
     * calendar details shown right below it.
     */
    public function passengerCount(): ?int
    {
        $fromCalendar = $this->calendarEvent?->descriptionValue('Passengers');
        if ($fromCalendar !== null && preg_match('/\d+/', $fromCalendar, $m)) {
            return (int) $m[0];
        }

        return $this->passengers !== null ? (int) $this->passengers : null;
    }

    /**
     * Do the three pickup times agree — the booking (shown on the dashboard and
     * bookings list), the calendar event's slot (start_at), and the time printed
     * in the event's description? Returns each source's time for a notification
     * when they differ, or [] when they match (or there's no linked event).
     * Read-only — purely a consistency check.
     *
     * @return array<string, string>  e.g. ['Booking' => 'Wed 15 Jul, 07:45', ...]
     */
    public function pickupTimeMismatch(): array
    {
        $event = $this->calendarEvent;
        if (! $event || blank($event->google_event_id)) {
            return [];
        }

        $times = array_filter([
            'Booking' => $this->pickup_at,
            'Calendar event' => $event->start_at,
            'Calendar description' => $event->descriptionPickupAt(),
        ]);

        $distinct = collect($times)->map(fn ($t) => $t->format('Y-m-d H:i'))->unique();
        if ($distinct->count() <= 1) {
            return [];
        }

        return collect($times)->map(fn ($t) => $t->format('D d M, H:i'))->all();
    }

    /** Generate the next unique booking reference, e.g. CET-2A4F9C. */
    public static function generateReference(): string
    {
        do {
            $reference = 'CET-'.strtoupper(bin2hex(random_bytes(3)));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }
}
