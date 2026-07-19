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
            'masking_disabled' => 'boolean',
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

    /**
     * A secret token for the shareable driver link — lets a cover driver open
     * their job page (details, cash, masked number, navigate, status, GPS)
     * without a login. Generated once and stored in meta; the token IS the key.
     */
    public function driverLinkToken(): string
    {
        // Prefer the dedicated indexed column (reliable); fall back to any legacy
        // token stored in meta so links already sent keep working.
        if (filled($this->driver_link_token ?? null)) {
            return $this->driver_link_token;
        }

        $token = $this->meta['driver_link_token'] ?? \Illuminate\Support\Str::random(40);

        $updates = ['meta' => array_merge($this->meta ?? [], ['driver_link_token' => $token])];
        if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'driver_link_token')) {
            $updates['driver_link_token'] = $token; // migrate/store on the column
        }
        $this->forceFill($updates)->save();

        return $token;
    }

    /** The full shareable driver-link URL. */
    public function driverLinkUrl(): string
    {
        return route('driver.link', $this->driverLinkToken());
    }

    /** Resolve a booking from a driver-link token, or null. */
    public static function byDriverLinkToken(string $token): ?self
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Indexed column first (fast, reliable); legacy meta fallback for links
        // sent before the column existed. try/catch survives pre-migration.
        try {
            if ($found = static::where('driver_link_token', $token)->first()) {
                return $found;
            }
        } catch (\Throwable) {
            // column not migrated yet — fall through to the meta lookup
        }

        try {
            if ($found = static::where('meta->driver_link_token', $token)->first()) {
                return $found;
            }
        } catch (\Throwable) {
            // JSON query quirk — fall through to the PHP scan
        }

        // Safety net: a valid link must never fail to resolve.
        return static::whereNotNull('meta->driver_link_token')->get()
            ->first(fn ($b) => ($b->meta['driver_link_token'] ?? null) === $token);
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
     * The number to drop into a DRIVER BRIEF (the block the office copies and
     * sends the driver on WhatsApp) — exactly what that driver would see on their
     * own job screen. A third-party / cover driver gets the masked switchboard
     * line (their real customer number is never pasted into a message); a director
     * driving their own job (Abdi / Maj), or an unmasked job, gets the real
     * customer number — masking a director from the customer serves no purpose.
     */
    public function driverBriefContact(): ?string
    {
        return $this->driverContactNumber();
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
        // Prefer the durable column (no sync ever rewrites it); fall back to the
        // old meta flag for jobs unmasked before the column existed, and so it
        // still works if the migration hasn't been run yet.
        return (bool) $this->getAttribute('masking_disabled')
            || (bool) ($this->meta['masking_disabled'] ?? false);
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
        // No masking on either side when an owner (admin) drives their own job,
        // or the office has unmasked it → the customer gets the driver's REAL
        // number (kept consistent with the driver's own screen).
        if ($this->driverSeesRealNumber()) {
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

    /**
     * Other LIVE bookings that look like the same journey (a likely duplicate):
     * same pickup minute AND the same customer OR the same drop-off. Cancelled /
     * no-show jobs are ignored. Used to flag double-bookings that slipped in via
     * two different references.
     */
    public function duplicateCandidates(): \Illuminate\Support\Collection
    {
        if (! $this->pickup_at) {
            return collect();
        }

        $name = \Illuminate\Support\Str::lower(trim((string) ($this->displayCustomerName() ?? '')));
        $dropoff = \Illuminate\Support\Str::lower(trim((string) $this->destination_address));

        return static::query()
            ->where('id', '!=', $this->id)
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('pickup_at', [$this->pickup_at->copy()->subMinute(), $this->pickup_at->copy()->addMinute()])
            ->with('customer')
            ->get()
            ->filter(function (self $b) use ($name, $dropoff) {
                $bName = \Illuminate\Support\Str::lower(trim((string) ($b->displayCustomerName() ?? '')));
                $bDrop = \Illuminate\Support\Str::lower(trim((string) $b->destination_address));

                return ($name !== '' && $bName === $name)
                    || ($dropoff !== '' && $bDrop === $dropoff);
            })
            ->values();
    }

    /** True when another live booking looks like the same journey. */
    public function looksDuplicated(): bool
    {
        return $this->duplicateCandidates()->isNotEmpty();
    }

    /**
     * If this job is completed but has NO review request, explain why (so the
     * office isn't left wondering). Returns null when a review exists, the job
     * isn't complete, or a review is simply still to be prepared.
     */
    public function reviewSkipReason(): ?string
    {
        if ($this->status->value !== 'complete') {
            return null;
        }
        if ($this->messages()->where('type', 'review_request')->exists()) {
            return null; // it has one
        }
        if (blank($this->customer?->phone)) {
            return 'No review request — no phone number on file for this customer.';
        }
        if ($this->customer_id) {
            $otherIds = static::where('customer_id', $this->customer_id)
                ->where('id', '!=', $this->id)->pluck('id');
            if ($otherIds->isNotEmpty()
                && \App\Models\Message::where('type', 'review_request')->whereIn('booking_id', $otherIds)->exists()) {
                return 'No review request — this customer was already asked on an earlier booking (once per customer).';
            }
        }

        return null;
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

    /**
     * The number to actually MESSAGE the customer on for THIS booking.
     *
     * This is deliberately the booking's OWN contact — the calendar "Contact No",
     * which is the number shown on the booking page — falling back to the linked
     * customer record's phone only when the booking carries no contact of its own.
     *
     * Why: a customer record can be shared across bookings (matched by email or
     * phone on import), so its stored phone may belong to a different/earlier
     * booker. Sending to it would text the WRONG person. Routing every customer
     * message through this method guarantees the reminder/confirmation goes to the
     * exact number the operator sees on the booking — never a stale merged number.
     */
    public function customerContactNumber(): ?string
    {
        return $this->displayContact();
    }

    /**
     * Data-integrity check: the calendar's "Contact No" for this booking when it
     * DISAGREES with the linked customer record's stored phone — otherwise null.
     *
     * A mismatch means the booking is filed under a customer record carrying a
     * different number (e.g. an old email-merge), so anything that reads the
     * record's phone instead of the booking would reach the wrong person. The
     * calendar is the source of truth, so this returns the calendar number to
     * show/repair to. Null when there's no calendar contact, no record phone, or
     * they already agree.
     */
    public function contactNumberMismatch(): ?string
    {
        $calendar = $this->calendarField('Contact No');
        $record = $this->customer?->phone;
        if (blank($calendar) || blank($record)) {
            return null;
        }

        return \App\Support\Phone::wa($calendar) !== \App\Support\Phone::wa($record)
            ? $calendar
            : null;
    }

    /** Meet & greet note as printed on the calendar, if present. */
    public function displayMeetAndGreet(): ?string
    {
        return $this->calendarField('Meet & Greet');
    }

    /**
     * Child/booster/infant seats for the driver — the calendar's counts win,
     * else the booking's own meta. e.g. "4 child · 1 booster", or null when none.
     */
    public function displayChildSeats(): ?string
    {
        $parts = [];
        foreach (['child_seats' => 'child', 'booster_seats' => 'booster', 'infant_seats' => 'infant'] as $metaKey => $word) {
            // The booking's OWN count wins (it's what the office edits); fall back
            // to the calendar text only when the booking doesn't carry a count.
            $n = $this->meta[$metaKey] ?? null;
            if ($n === null || $n === '') {
                $n = $this->calendarField(ucwords(str_replace('_', ' ', $metaKey)));
            }
            $n = (int) $n;
            if ($n > 0) {
                $parts[] = $n.' '.$word.' '.\Illuminate\Support\Str::plural('seat', $n);
            }
        }

        if ($parts === []) {
            // Only a yes/no flag with no count → assume a single seat.
            return (bool) ($this->meta['child_seat'] ?? false) ? '1 child seat' : null;
        }

        return implode(' · ', $parts);
    }

    /** Whether this job carries any child/booster/infant seat (for the 🚼 mark). */
    public function hasChildSeat(): bool
    {
        return $this->displayChildSeats() !== null;
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
     * What the driver must physically COLLECT from the customer on this job.
     * Prefers the office-curated calendar Payment line (e.g. "Deposit £10 Paid
     * – £110 Cash Due"); otherwise derives a cash amount for an unpaid cash job.
     * Returns null when there's nothing to collect (fully prepaid) or unknown —
     * so a card/prepaid job never shows the fare on the driver's screen.
     */
    public function driverCollectLine(): ?string
    {
        // The office-curated payment line — the calendar's, else the booking's own.
        $line = trim((string) ($this->displayPayment() ?: ($this->meta['payment_text'] ?? '')));
        if ($line !== '') {
            // Pull out JUST the amount still to collect (cash) — the driver never
            // needs the deposit-already-paid part, only what to take on the day.
            // Handle both orders: "£110 Cash Due" and "Cash £90".
            if (preg_match('/£\s?([\d,]+(?:\.\d{1,2})?)\s*(?:cash due|to collect|cash|due|outstanding|balance)/i', $line, $m)
                || preg_match('/(?:cash|collect|outstanding|balance|due)[^£]*£\s?([\d,]+(?:\.\d{1,2})?)/i', $line, $m)) {
                return '£'.$m[1].' to collect (cash)';
            }
            if (preg_match('/paid/i', $line)) {
                return 'Paid — collect nothing';
            }
        }

        // No usable calendar line — fall back to the booking's own fields.
        $amount = $this->final_price ?? $this->quoted_price;
        if (($this->payment_method?->value ?? null) === 'cash'
            && $this->payment_status !== 'paid' && $amount) {
            return '£'.number_format((float) $amount, 2).' to collect (cash)';
        }
        if ($this->payment_status === 'paid') {
            return 'Paid — collect nothing';
        }

        return null;
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

    /**
     * ---- Tips (driver gratuities per job) ----------------------------------
     * Stored in their OWN table (booking_tips) so a sync rewriting meta can never
     * wipe them. Tips are the DRIVER's, on top of job pay: a cash tip is already
     * in the driver's hand; a card tip is collected by the company and owed.
     */
    public function tipEntries(): HasMany
    {
        return $this->hasMany(BookingTip::class);
    }

    /**
     * Tip rows shaped for the views (newest last), from the ledger.
     *
     * @return array<int, array{amount: float, method: string, at: string, by: ?string, note: ?string}>
     */
    public function tips(): array
    {
        return $this->tipEntries()->orderBy('id')->get()->map(fn (BookingTip $t) => [
            'amount' => (float) $t->amount,
            'method' => $t->method,
            'at' => (string) $t->created_at,
            'by' => $t->logged_by,
            'note' => $t->note,
        ])->all();
    }

    /** Every tip on this job, cash + card. */
    public function tipsTotal(): float
    {
        return round((float) $this->tipEntries()->sum('amount'), 2);
    }

    /** Tips by method — 'cash' the driver already holds, 'card' the company collected. */
    public function tipsTotalBy(string $method): float
    {
        return round((float) $this->tipEntries()->where('method', $method)->sum('amount'), 2);
    }

    /** Card tips are collected by the company, so they're owed to the driver. */
    public function cardTipsOwed(): float
    {
        return $this->tipsTotalBy('card');
    }

    /** Log a tip against this job (cash or card). Returns the new ledger row. */
    public function logTip(float $amount, string $method, ?string $note = null, ?string $loggedBy = null, string $source = 'manual', ?string $squarePaymentId = null): BookingTip
    {
        return $this->tipEntries()->create([
            'amount' => round($amount, 2),
            'method' => $method,
            'source' => $source,
            'note' => $note,
            'logged_by' => $loggedBy,
            'square_payment_id' => $squarePaymentId,
        ]);
    }

    /**
     * A secret token for the customer TIP link (no login). Stored in a dedicated
     * indexed column (reliable) with a meta copy for back-compat, and generated
     * once — a shared link stays valid. Mirrors driverLinkToken().
     */
    public function tipToken(): string
    {
        // Prefer the indexed column; fall back to any legacy meta token so links
        // already sent keep working.
        if (filled($this->tip_token ?? null)) {
            return $this->tip_token;
        }

        // Short, clean token — this goes in a message a customer sees, so keep
        // the URL tidy. 10 url-safe chars is ~8×10^17 combinations: unguessable
        // for a tip link, and the worst case if one were guessed is a stranger
        // tipping a random driver.
        $token = $this->meta['tip_token'] ?? \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));

        $updates = ['meta' => array_merge($this->meta ?? [], ['tip_token' => $token])];
        if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'tip_token')) {
            $updates['tip_token'] = $token; // migrate/store on the indexed column
        }
        $this->forceFill($updates)->save();

        return $token;
    }

    /** The full customer tip-link URL. */
    public function tipUrl(): string
    {
        return route('tip.show', $this->tipToken());
    }

    /** Resolve a booking from a tip token, or null. Bulletproof: never misses. */
    public static function byTipToken(string $token): ?self
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Indexed column first (fast, reliable).
        try {
            if ($booking = static::where('tip_token', $token)->first()) {
                return $booking;
            }
        } catch (\Throwable) {
            // column not migrated yet — fall through to the meta lookup
        }

        // Legacy meta JSON lookup for links stored before the column existed.
        try {
            if ($booking = static::where('meta->tip_token', $token)->first()) {
                return $booking;
            }
        } catch (\Throwable) {
            // JSON query unsupported/odd on this DB — fall through to the scan.
        }

        // Safety net: scan bookings that carry a tip token and match in PHP, so a
        // valid link can never fail to resolve because of a JSON-query quirk.
        return static::whereNotNull('meta->tip_token')->get()
            ->first(fn ($b) => ($b->meta['tip_token'] ?? null) === $token);
    }

    /**
     * Log a card tip that came in through Square, keyed by the Square payment id
     * so a re-delivered webhook never double-counts. Returns true if it was newly
     * recorded, false if it was already logged.
     */
    public function logSquareTip(float $amount, string $paymentId, ?string $note = null): bool
    {
        // Idempotent: the DB unique index on square_payment_id is the real guard.
        if (BookingTip::where('square_payment_id', $paymentId)->exists()) {
            return false;
        }

        $this->logTip(
            $amount,
            'card',
            note: $note ?: 'Card tip via Square',
            loggedBy: 'Square',
            source: 'square',
            squarePaymentId: $paymentId,
        );

        return true;
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
