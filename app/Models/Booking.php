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

    public function proxySessions(): HasMany
    {
        return $this->hasMany(ProxySession::class);
    }

    public function driverLocations(): HasMany
    {
        return $this->hasMany(DriverLocation::class);
    }

    /** The most recent GPS ping recorded for this job (or null). */
    public function latestLocation(): ?DriverLocation
    {
        return $this->driverLocations()->orderByDesc('captured_at')->first();
    }

    /** When the office last asked the driver to share their location. */
    public function locationRequestedAt(): ?\Illuminate\Support\Carbon
    {
        $at = $this->meta['location_request_at'] ?? null;

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /**
     * True when the office has asked for a location and the driver hasn't
     * answered it yet (no ping newer than the request). Drives the one-off
     * share on the driver's job screen.
     */
    public function locationRequestPending(): bool
    {
        $requestedAt = $this->locationRequestedAt();
        if (! $requestedAt || $this->status->isTerminal()) {
            return false;
        }

        $latest = $this->latestLocation();

        return ! $latest || $latest->captured_at->lt($requestedAt);
    }

    /**
     * The ONLY customer contact number a driver may ever see: the masked
     * Twilio Proxy line of the open session. Null when masking isn't active —
     * in which case driver-facing screens show NO number at all (the office
     * relays anything urgent). The real number never reaches a driver view.
     */
    public function driverContactNumber(): ?string
    {
        // An admin driving their own job (Abdi / Maj), or a job the office has
        // unmasked, gets the customer's real number.
        if ($this->driverSeesRealNumber()) {
            return $this->customer?->phone;
        }

        // Switchboard: the driver rings the permanent CET driver line to reach
        // the customer (same number on every job — safe to show in advance).
        if (filled($line = config('services.twilio_masking.driver_line'))) {
            return $line;
        }

        try {
            return $this->proxySessions()->open()->latest('opened_at')->value('masked_number');
        } catch (\Throwable) {
            return null; // masking tables not migrated yet — never break a page
        }
    }

    /**
     * True when the office has switched masking OFF for this specific job — e.g.
     * a return leg where the customer and driver already have each other's
     * numbers from the outbound, so a masked line just gets in the way.
     */
    public function maskingDisabled(): bool
    {
        return (bool) ($this->meta['masking_disabled'] ?? false);
    }

    /**
     * True when the driver's job screen shows the customer's REAL number rather
     * than a masked Twilio line: either the driver is an admin/owner (trusted
     * with it, and it saves a credit), or masking was turned off for this job.
     */
    public function driverSeesRealNumber(): bool
    {
        return ($this->driver?->isAdmin() ?? false) || $this->maskingDisabled();
    }

    /** The masked line the CUSTOMER dials/receives from (for driver-details messages). */
    public function customerMaskedNumber(): ?string
    {
        // Unmasked job → no CET line; the customer gets the real driver number.
        if ($this->maskingDisabled()) {
            return null;
        }

        // Switchboard: the customer rings the permanent CET customer line to
        // reach whoever's driving this job (same number every time).
        if (filled($line = config('services.twilio_masking.customer_line'))) {
            return $line;
        }

        try {
            return $this->proxySessions()->open()->latest('opened_at')->value('customer_masked_number');
        } catch (\Throwable) {
            return null; // masking tables not migrated yet — never break a booking
        }
    }

    // ----- Scopes --------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BookingStatus::Accepted->value,
            BookingStatus::EnRoute->value,
            BookingStatus::Arrived->value,
            BookingStatus::Collected->value,
        ]);
    }

    /** Jobs in a GPS-tracked state — from the Set off tap until Complete. */
    public function scopeTracked(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BookingStatus::EnRoute->value,
            BookingStatus::Arrived->value,
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
        return self::flightRadarLink($this->displayFlightNumber());
    }

    public function flightSearchUrl(): ?string
    {
        return self::flightSearchLink($this->displayFlightNumber());
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
     * A single field mirrored from the calendar description (the operator's
     * source of truth), or null when there's no linked event or no such line.
     * Underpins the display accessors below so every field on the booking page
     * agrees with the "Full details (from the calendar)" block beneath it.
     */
    public function calendarField(string $label): ?string
    {
        return $this->calendarEvent?->descriptionValue($label);
    }

    /** Pickup address — the calendar's "Pickup Location" wins, else our own. */
    public function displayPickupAddress(): ?string
    {
        return $this->calendarField('Pickup Location') ?: $this->pickup_address;
    }

    /** Drop-off address — the calendar's "Drop-off Location" wins, else our own. */
    public function displayDropoffAddress(): ?string
    {
        return $this->calendarField('Drop-off Location') ?: $this->destination_address;
    }

    /** Flight number — the calendar's "Flight Number" wins, else our own. */
    public function displayFlightNumber(): ?string
    {
        $flight = $this->calendarField('Flight Number') ?: $this->flight_number;

        // The builder prints "N/A" where there's no flight — treat that as none.
        return ($flight && strcasecmp(trim($flight), 'N/A') !== 0) ? $flight : null;
    }

    /** Vehicle type name — the calendar's "Vehicle Type" wins, else our own. */
    public function displayVehicleType(): ?string
    {
        return $this->calendarField('Vehicle Type') ?: $this->vehicleType?->name;
    }

    /** Customer/lead name — the calendar's "Customer Name" wins, else our own. */
    public function displayCustomerName(): ?string
    {
        return $this->calendarField('Customer Name') ?: $this->customer?->name;
    }

    /** Contact number — the calendar's "Contact No" wins, else the customer's. */
    public function displayContact(): ?string
    {
        return $this->calendarField('Contact No') ?: $this->customer?->phone;
    }

    /** Meet & greet note as printed on the calendar, if present. */
    public function displayMeetAndGreet(): ?string
    {
        return $this->calendarField('Meet & Greet');
    }

    /**
     * Payment line exactly as printed on the calendar, e.g. "Paid £350 (Stripe)",
     * or null so the booking's own structured payment fields are shown instead.
     */
    public function displayPayment(): ?string
    {
        return $this->calendarField('Payment');
    }

    /**
     * ---- Payroll (driver pay per job) --------------------------------------
     * Stored in meta['payroll'] so no migration is needed:
     *   ['pay' => 45.00, 'paid' => 20.00, 'history' => [{amount, at, by, note}]]
     */

    /** What this job pays the driver, or null when not set yet. */
    public function driverPay(): ?float
    {
        $pay = $this->meta['payroll']['pay'] ?? null;

        return $pay !== null ? (float) $pay : null;
    }

    /** How much of the driver's pay has been handed over so far. */
    public function driverPaidAmount(): float
    {
        return (float) ($this->meta['payroll']['paid'] ?? 0);
    }

    /** What's still owed to the driver for this job (never negative). */
    public function driverPayRemaining(): ?float
    {
        $pay = $this->driverPay();

        return $pay === null ? null : max(0, round($pay - $this->driverPaidAmount(), 2));
    }

    /** @return array<int, array{amount: float, at: string, by: ?string, note: ?string}> */
    public function driverPayHistory(): array
    {
        return $this->meta['payroll']['history'] ?? [];
    }

    /** The name payroll groups by — assigned driver, else the manual job driver. */
    public function payrollDriverName(): string
    {
        return $this->driver?->name
            ?? ($this->meta['driver_details']['name'] ?? null)
            ?? 'Unassigned';
    }

    /**
     * The driver name shown on the PUBLIC tracking page — first name/callsign
     * only, never the full name (privacy: the customer needs "Abdi is on the
     * way", not the driver's full identity).
     */
    public function driverPublicName(): ?string
    {
        $driver = $this->driver;
        if (! $driver) {
            $manual = $this->meta['driver_details']['name'] ?? null;

            return $manual ? \Illuminate\Support\Str::before(trim($manual), ' ') : null;
        }

        return $driver->driverProfile?->callsign
            ?: \Illuminate\Support\Str::before(trim($driver->name), ' ');
    }

    /**
     * The friendly journey message for the customer tracking page, keyed off
     * the booking status. Deliberately vague on internals — status only.
     */
    public function trackingMessage(): string
    {
        return match ($this->status->value) {
            'en_route' => 'Your driver is on the way',
            'arrived' => 'Your driver has arrived at the pickup',
            'collected' => 'Passenger on board — journey underway',
            'complete' => 'Journey completed — thank you for travelling with us',
            'cancelled', 'no_show' => 'This journey is closed',
            default => $this->driver_id ? 'Your driver has been allocated' : 'Your booking is confirmed',
        };
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
