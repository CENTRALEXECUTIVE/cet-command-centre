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

    /**
     * Intermediate stops (via points) for this journey — the places the driver
     * must travel to BETWEEN pickup and drop-off. Taken from the stops table
     * (structured) or, failing that, ETO's free-text "Via" field. Returns the
     * addresses in order.
     *
     * @return array<int, string>
     */
    public function viaStops(): array
    {
        // 1) Structured stops from the booking form (the stops table).
        $fromTable = $this->stops->pluck('address')
            ->map(fn ($a) => trim((string) $a))->filter()->values()->all();
        if ($fromTable) {
            return $fromTable;
        }

        // 2) Intake paths (Outlook / ETO email, paste-a-booking, the calendar
        //    description) store stops as a plain list in meta['stops'].
        $fromMeta = array_values(array_filter(array_map(
            fn ($s) => trim((string) (is_array($s) ? ($s['address'] ?? '') : $s)),
            (array) ($this->meta['stops'] ?? []),
        )));
        if ($fromMeta) {
            return $fromMeta;
        }

        // 3) The ETO CSV import's free-text "Via" field (one or more, joined).
        $via = trim((string) ($this->meta['eto_via'] ?? ''));
        if ($via === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\s*[;\n]\s*/', $via))));
    }

    public function hasViaStops(): bool
    {
        return count($this->viaStops()) > 0;
    }

    /** How many via stops the driver has already reached (tapped through). */
    public function stopsReached(): int
    {
        return max(0, (int) ($this->meta['stops_reached'] ?? 0));
    }

    /** The next via stop the driver should travel to, or null if all are done. */
    public function nextViaStop(): ?string
    {
        return $this->viaStops()[$this->stopsReached()] ?? null;
    }

    /** True once every via stop has been reached (so drop-off/Complete is next). */
    public function allViaStopsReached(): bool
    {
        return $this->stopsReached() >= count($this->viaStops());
    }

    /** Advance the "reached" counter by one stop (capped at the total). */
    public function markStopReached(): void
    {
        $meta = $this->meta ?? [];
        $meta['stops_reached'] = min(count($this->viaStops()), $this->stopsReached() + 1);
        $this->forceFill(['meta' => $meta])->save();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    /**
     * ---- Waiting time -------------------------------------------------------
     * The customer gets a free grace period once the driver is AT the pickup;
     * only after it does billable waiting time start counting. Crucially the
     * clock is anchored to when the driver's GPS actually confirms them at the
     * pickup — NOT to the "Arrived" tap — so tapping Arrived from home (or
     * without sharing location) never starts the timer. The final billable
     * figure is frozen when the passenger boards.
     */

    /** How close to the pickup a GPS ping must be to count as "at the pickup". */
    public const WAITING_GEOFENCE_M = 200;

    /** Minutes of free waiting the customer gets once the driver is at pickup. */
    public function waitingGraceMinutes(): int
    {
        return max(0, (int) config('cet.waiting_grace_minutes', 15));
    }

    /**
     * When the driver marked ARRIVED at the pickup, from the audit trail. This
     * is only the EARLIEST the waiting clock could start — it isn't the anchor
     * on its own (see waitingStartedAt). Null until the driver has arrived.
     */
    public function arrivedAt(): ?\Illuminate\Support\Carbon
    {
        $history = $this->relationLoaded('statusHistory')
            ? $this->statusHistory
            : $this->statusHistory()->get();

        return $history->where('to_status', BookingStatus::Arrived->value)
            ->sortByDesc('created_at')->first()?->created_at;
    }

    /** Geocoded pickup coordinates [lat, lng] from meta['geo'], or null. */
    public function pickupCoords(): ?array
    {
        $geo = $this->meta['geo']['pickup'] ?? null;

        return isset($geo[0], $geo[1]) ? [(float) $geo[0], (float) $geo[1]] : null;
    }

    /**
     * When the waiting clock actually starts: the first GPS ping that puts the
     * driver INSIDE the pickup geofence at or after the Arrived tap. Anchoring
     * to a confirmed-at-pickup position (not the tap) is what stops a driver who
     * taps "Arrived" at home from running up waiting time.
     *
     * If the pickup can't be geocoded we can't verify presence, so we fall back
     * to the Arrived tap — but ONLY when location is actually being shared (a
     * ping exists since arrival). No location shared → no confirmed start.
     * Null means "not confirmed at the pickup yet" (timer hasn't started).
     */
    public function waitingStartedAt(): ?\Illuminate\Support\Carbon
    {
        $arrived = $this->arrivedAt();
        if (! $arrived) {
            return null;
        }

        $pings = $this->driverLocations()
            ->where('captured_at', '>=', $arrived)
            ->orderBy('captured_at')->get();

        $pickup = $this->pickupCoords();
        if ($pickup !== null) {
            foreach ($pings as $p) {
                $m = \App\Support\Geo::haversineMeters((float) $p->latitude, (float) $p->longitude, $pickup[0], $pickup[1]);
                // Forgive a "little GPS error": widen the geofence by the ping's
                // own reported accuracy (capped), so a genuine arrival with a
                // slightly-off fix still counts — while a gross mismatch (tapped
                // Arrived from home, miles away) is still excluded.
                $tolerance = min((float) ($p->accuracy ?? 0), self::WAITING_GEOFENCE_M);
                if ($m <= self::WAITING_GEOFENCE_M + $tolerance) {
                    return $p->captured_at;
                }
            }

            return null; // arrived, but never confirmed anywhere near the pickup
        }

        // No coords to check against — trust the tap only if location is live.
        return $pings->isNotEmpty() ? $arrived : null;
    }

    /** True once the driver's GPS has confirmed them at the pickup. */
    public function waitingConfirmedAtPickup(): bool
    {
        return $this->waitingStartedAt() !== null;
    }

    /**
     * Billable waiting minutes so far (whole minutes past the grace period),
     * measured from the confirmed-at-pickup start to $at (default now). 0 while
     * inside the grace period, before arrival, or before GPS confirms presence.
     */
    public function waitingBillableMinutes(?\Illuminate\Support\Carbon $at = null): int
    {
        $start = $this->waitingStartedAt();
        if (! $start) {
            return 0;
        }
        $elapsed = $start->diffInMinutes($at ?? now());

        return (int) max(0, $elapsed - $this->waitingGraceMinutes());
    }

    /** The billable waiting minutes recorded when the passenger boarded, if any. */
    public function recordedWaitingMinutes(): ?int
    {
        $v = $this->meta['waiting']['billable_minutes'] ?? null;

        return $v === null ? null : (int) $v;
    }

    /** Freeze the waiting time onto the booking (called when the passenger boards). */
    public function recordWaitingTime(): void
    {
        // Only records when GPS confirmed the driver at the pickup — a home tap
        // with no location shared leaves nothing to charge.
        if (! $this->waitingConfirmedAtPickup()) {
            return;
        }
        $meta = $this->meta ?? [];
        $meta['waiting'] = [
            'billable_minutes' => $this->waitingBillableMinutes(),
            'grace_minutes' => $this->waitingGraceMinutes(),
            'recorded_at' => now()->toIso8601String(),
        ];
        $this->forceFill(['meta' => $meta])->save();
    }

    /**
     * The booking's calendar event. There is no unique constraint on
     * calendar_events.booking_id and several code paths can create a row, so a
     * booking may hold more than one. Without an explicit order a plain hasOne
     * returns an ARBITRARY row per query — which made the driver's Payment/
     * "Collect" line flip between reloads (one event said "Paid", another "£X
     * Cash Due"). latestOfMany pins it to the newest event so every read is
     * deterministic. (Money correctness is further guaranteed in cashDueToDriver
     * by reading every payment source, not just this one.)
     */
    public function calendarEvent(): HasOne
    {
        return $this->hasOne(CalendarEvent::class)->orderByDesc('id');
    }

    /** All calendar-event rows for this booking (there can be more than one). */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
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

    /**
     * The WhatsApp message that goes out WITH the driver link — deliberately
     * minimal: just the customer name and the day + date/time (numbers), enough
     * to recognise the job and not forget it. Everything else (route, flight,
     * cash, contact, navigation, status) lives behind the link.
     */
    public function driverLinkMessage(): string
    {
        $lines = ['Central Executive Transfers', ''];

        $name = $this->meta['lead_name'] ?? $this->displayCustomerName();
        if (filled($name)) {
            $lines[] = $name;
        }

        if ($this->pickup_at) {
            // Weekday + date/time in numbers — the bit to remember at a glance.
            $lines[] = $this->pickup_at->format('D d/m/Y H:i');
        }

        $lines[] = '';
        $lines[] = 'Open your job sheet:';
        $lines[] = $this->driverLinkUrl();

        return implode("\n", $lines);
    }

    /* ── Additional drivers (multi-car jobs, e.g. a 3-car wedding) ─────────────
     * The primary driver stays on driver_id (Car 1 / lead). Each EXTRA car is a
     * name/phone + its own shareable link token and its own per-car status, all
     * stored in meta['extra_drivers'] (no migration). Extra drivers reach the
     * customer via the office (no masking), and their GPS isn't tracked — only
     * their status is, so the office sees each car's progress independently.
     */

    /** @return array<int, array<string, mixed>> */
    public function extraDrivers(): array
    {
        return array_values($this->meta['extra_drivers'] ?? []);
    }

    public function hasExtraDrivers(): bool
    {
        return count($this->extraDrivers()) > 0;
    }

    /** Total cars on the job (lead + extras) — 1 unless it's a multi-car job. */
    public function carCount(): int
    {
        return 1 + count($this->extraDrivers());
    }

    /** Add an extra car; returns its new link token. */
    public function addExtraDriver(array $attrs): string
    {
        $token = \Illuminate\Support\Str::random(40);
        $extras = $this->extraDrivers();
        $extras[] = [
            'token' => $token,
            'name' => trim((string) ($attrs['name'] ?? '')) ?: 'Driver',
            'phone' => trim((string) ($attrs['phone'] ?? '')),
            'reg' => trim((string) ($attrs['reg'] ?? '')),
            'car' => trim((string) ($attrs['car'] ?? '')),
            'status' => BookingStatus::Allocated->value,
        ];
        $this->forceFill(['meta' => array_merge($this->meta ?? [], ['extra_drivers' => array_values($extras)])])->save();

        return $token;
    }

    public function removeExtraDriver(string $token): void
    {
        $extras = array_values(array_filter($this->extraDrivers(), fn ($d) => ($d['token'] ?? null) !== $token));
        $this->forceFill(['meta' => array_merge($this->meta ?? [], ['extra_drivers' => $extras])])->save();
    }

    /** The extra-car entry for a token, or null. */
    public function extraDriver(string $token): ?array
    {
        foreach ($this->extraDrivers() as $d) {
            if (($d['token'] ?? null) === $token) {
                return $d;
            }
        }

        return null;
    }

    /** Its position for display ("Car 2 of 3"). Lead is Car 1, so extras start at 2. */
    public function extraDriverCarNumber(string $token): ?int
    {
        foreach ($this->extraDrivers() as $i => $d) {
            if (($d['token'] ?? null) === $token) {
                return $i + 2;
            }
        }

        return null;
    }

    /** Mutate a single extra-car entry in place (by token) and persist. */
    private function updateExtraDriver(string $token, callable $mutate): void
    {
        $extras = $this->extraDrivers();
        foreach ($extras as $i => $d) {
            if (($d['token'] ?? null) === $token) {
                $extras[$i] = $mutate($d);
            }
        }
        $this->forceFill(['meta' => array_merge($this->meta ?? [], ['extra_drivers' => array_values($extras)])])->save();
    }

    /** Update one extra car's per-car status. */
    public function setExtraDriverStatus(string $token, string $status): void
    {
        $this->updateExtraDriver($token, function (array $d) use ($status) {
            $d['status'] = $status;

            return $d;
        });
    }

    /* Per-car PAYROLL — each extra car is paid separately from the lead driver
     * and from each other. Pay/paid/history live inside the car's own entry. */

    public function extraDriverPay(string $token): ?float
    {
        $d = $this->extraDriver($token);

        return isset($d['pay']) && $d['pay'] !== null ? (float) $d['pay'] : null;
    }

    public function extraDriverPaidAmount(string $token): float
    {
        return (float) ($this->extraDriver($token)['paid'] ?? 0);
    }

    public function extraDriverPayRemaining(string $token): ?float
    {
        $pay = $this->extraDriverPay($token);

        return $pay === null ? null : round(max(0.0, $pay - $this->extraDriverPaidAmount($token)), 2);
    }

    public function setExtraDriverPay(string $token, float $amount): void
    {
        $this->updateExtraDriver($token, function (array $d) use ($amount) {
            $d['pay'] = round($amount, 2);

            return $d;
        });
    }

    public function recordExtraDriverPayment(string $token, float $amount, ?string $by = null, ?string $note = null): void
    {
        $this->updateExtraDriver($token, function (array $d) use ($amount, $by, $note) {
            $d['paid'] = round((float) ($d['paid'] ?? 0) + $amount, 2);
            $d['history'][] = ['amount' => round($amount, 2), 'at' => now()->toDateTimeString(), 'by' => $by, 'note' => $note];

            return $d;
        });
    }

    public function extraDriverLinkUrl(string $token): string
    {
        return route('driver.car', $token);
    }

    /** Resolve the booking that owns an extra-car token (unique 40-char string). */
    public static function byExtraDriverToken(string $token): ?self
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return static::where('meta', 'like', '%'.$token.'%')->get()
            ->first(fn (self $b) => $b->extraDriver($token) !== null);
    }

    /**
     * A journey signature: the pickup minute + the customer (phone if we have
     * it, else the name). Two records that share it are the same journey. Null
     * when we can't identify it confidently — those are never matched, so we
     * never collapse two different jobs that merely share a time.
     */
    public function journeySignature(): ?string
    {
        if (! $this->pickup_at) {
            return null;
        }

        $phone = \App\Support\Phone::wa($this->customer?->phone);
        if (filled($phone)) {
            $customer = 'p:'.$phone;
        } elseif (filled($this->customer?->name)) {
            $customer = 'n:'.strtolower(preg_replace('/\s+/', ' ', trim($this->customer->name)));
        } else {
            return null;
        }

        return $this->pickup_at->format('YmdHi').'|'.$customer;
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

        // Aliases: a link sent for a copy that was later MERGED into another
        // booking must still resolve to the survivor (the token is carried over).
        try {
            if ($found = static::whereJsonContains('meta->driver_link_aliases', $token)->first()) {
                return $found;
            }
        } catch (\Throwable) {
            // fall through to the PHP scan
        }

        // Safety net: a valid link must never fail to resolve.
        return static::where(fn ($q) => $q->whereNotNull('meta->driver_link_token')
                ->orWhereNotNull('meta->driver_link_aliases'))
            ->get()
            ->first(fn ($b) => ($b->meta['driver_link_token'] ?? null) === $token
                || in_array($token, (array) ($b->meta['driver_link_aliases'] ?? []), true));
    }

    /**
     * The booking that owns this external/ETO reference — matching the live
     * external_reference OR a reference that was previously MERGED into this
     * booking (meta['merged_references']). Every import path uses this so a
     * reference already folded into another booking updates THAT booking instead
     * of being re-created as a fresh duplicate ("don't separate them again").
     */
    public static function resolveByReference(?string $reference): ?self
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        // Live reference first (indexed, fast).
        if ($found = static::where('external_reference', $reference)->first()) {
            return $found;
        }

        // A merged-away reference carried as an alias on the survivor.
        try {
            if ($found = static::whereJsonContains('meta->merged_references', $reference)->first()) {
                return $found;
            }
        } catch (\Throwable) {
            // JSON query unsupported — fall through to the PHP scan.
        }

        return static::where('meta', 'like', '%"'.$reference.'"%')
            ->get()
            ->first(fn (self $b) => in_array($reference, (array) ($b->meta['merged_references'] ?? []), true));
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

    /** Default masking timings — how long BEFORE pickup the line goes live, and
     *  how long AFTER drop-off it stays open. Both overridable per booking. */
    public const MASKING_LEAD_MINUTES = 90;

    public const MASKING_GRACE_HOURS = 4;

    /** Minutes before pickup the masked line goes live for this job. */
    public function maskingLeadMinutes(): int
    {
        $v = $this->meta['masking_lead_minutes'] ?? null;

        return $v !== null ? max(0, (int) $v) : self::MASKING_LEAD_MINUTES;
    }

    /** Hours after drop-off the masked line stays open for this job. */
    public function maskingGraceHours(): float
    {
        $v = $this->meta['masking_grace_hours'] ?? null;

        return $v !== null ? max(0, (float) $v) : self::MASKING_GRACE_HOURS;
    }

    /** When the masked line should go live — pickup minus the lead. Null if no pickup. */
    public function maskingOpensAt(): ?\Illuminate\Support\Carbon
    {
        return $this->pickup_at?->copy()->subMinutes($this->maskingLeadMinutes());
    }

    /** Whether we're inside the window where the masked line should be live. */
    public function maskingWindowOpen(): bool
    {
        $opensAt = $this->maskingOpensAt();

        return $opensAt !== null && now()->gte($opensAt);
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
        $myNotDupe = $this->notDuplicateKeys();
        $myKeys = $this->bookingKeys();

        return static::query()
            ->where('id', '!=', $this->id)
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('pickup_at', [$this->pickup_at->copy()->subMinute(), $this->pickup_at->copy()->addMinute()])
            ->with('customer')
            ->get()
            ->filter(function (self $b) use ($name, $dropoff, $myNotDupe, $myKeys) {
                $bName = \Illuminate\Support\Str::lower(trim((string) ($b->displayCustomerName() ?? '')));
                $bDrop = \Illuminate\Support\Str::lower(trim((string) $b->destination_address));

                $looksSame = ($name !== '' && $bName === $name)
                    || ($dropoff !== '' && $bDrop === $dropoff);
                if (! $looksSame) {
                    return false;
                }

                // The operator confirmed this pair is NOT a duplicate — two real
                // separate jobs that happen to share a time. Stay quiet about it
                // (checked both ways, since the flag is written on both records).
                $bKeys = $b->bookingKeys();
                if (array_intersect($bKeys, $myNotDupe) || array_intersect($myKeys, $b->notDuplicateKeys())) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /** Stable identifiers for this booking (its own ref + any external ref). */
    public function bookingKeys(): array
    {
        return array_values(array_filter([$this->reference, $this->external_reference], fn ($v) => filled($v)));
    }

    /** References the operator has confirmed are NOT the same journey as this one. */
    public function notDuplicateKeys(): array
    {
        return array_map('strval', (array) ($this->meta['not_duplicate_refs'] ?? []));
    }

    /**
     * Record that $other is a genuinely separate booking (not a duplicate of
     * this one) so it's never flagged again. Keyed by stable reference so it
     * survives a re-import. Symmetric — call once per side.
     */
    public function markNotDuplicateOf(self $other): void
    {
        $keys = array_values(array_unique(array_merge($this->notDuplicateKeys(), $other->bookingKeys())));
        $this->forceFill(['meta' => array_merge($this->meta ?? [], ['not_duplicate_refs' => $keys])])->save();
    }

    /** True when another live booking looks like the same journey. */
    public function looksDuplicated(): bool
    {
        return $this->duplicateCandidates()->isNotEmpty();
    }

    /** The fare for this job (agreed final, else the quote), or null. */
    public function fareAmount(): ?float
    {
        $amount = $this->final_price ?? $this->quoted_price;

        return $amount !== null ? (float) $amount : null;
    }

    /**
     * A wa.me deep link to message THIS job's driver (admin-side — the office
     * has the driver's real number). Null when there's no usable driver number.
     */
    public function driverWhatsAppLink(): ?string
    {
        $wa = \App\Support\Phone::wa($this->driver?->phone ?? ($this->meta['driver_details']['phone'] ?? null));

        return $wa ? 'https://wa.me/'.$wa : null;
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
        $fn = self::normaliseFlightNumberForFr24($flightNumber);

        return $fn !== '' ? 'https://www.flightradar24.com/data/flights/'.$fn : null;
    }

    /**
     * ICAO (3-letter) → IATA (2-letter) for the carriers a UK transfer firm
     * actually sees. Extend as needed.
     *
     * @return array<string, string>
     */
    private static function icaoToIata(): array
    {
        return [
            'BAW' => 'BA', 'VIR' => 'VS', 'EZY' => 'U2', 'RYR' => 'FR', 'EXS' => 'LS',
            'TOM' => 'BY', 'DLH' => 'LH', 'AFR' => 'AF', 'KLM' => 'KL', 'UAE' => 'EK',
            'QTR' => 'QR', 'ETD' => 'EY', 'DAL' => 'DL', 'UAL' => 'UA', 'AAL' => 'AA',
            'ACA' => 'AC', 'SWR' => 'LX', 'IBE' => 'IB', 'TAP' => 'TP', 'THY' => 'TK',
            'ELY' => 'LY', 'SAS' => 'SK', 'AUA' => 'OS', 'BEL' => 'SN', 'EIN' => 'EI',
            'WZZ' => 'W6', 'FIN' => 'AY', 'ICE' => 'FI', 'QFA' => 'QF', 'SIA' => 'SQ',
            'CPA' => 'CX', 'ANA' => 'NH', 'JAL' => 'JL', 'ETH' => 'ET', 'MSR' => 'MS',
            'RJA' => 'RJ', 'SVA' => 'SV', 'GFA' => 'GF', 'OMA' => 'WY', 'PIA' => 'PK',
            'AIC' => 'AI', 'THA' => 'TG', 'MAS' => 'MH', 'CCA' => 'CA', 'CES' => 'MU',
            'CSN' => 'CZ', 'KAL' => 'KE', 'AZA' => 'AZ', 'ITY' => 'AZ', 'LOT' => 'LO',
        ];
    }

    /**
     * Pull the airline code + flight number + optional suffix out of a value that
     * may carry extra text — "QR027 (Qatar Airways)", "BA 1234", "U22366 arrives
     * 19:10". Handles IATA codes that contain a DIGIT (easyJet U2, Wizz W6) and
     * 3-letter ICAO codes. Returns the IATA form, the ICAO callsign (when known)
     * and the bare number. Null when there's no recognisable flight code.
     *
     * @return array{iata: string, icao: ?string, number: string, suffix: string}|null
     */
    private static function parseFlight(?string $flightNumber): ?array
    {
        // Drop bracketed airline names so "(Qatar Airways)" can't be mis-parsed.
        $raw = preg_replace('/\(.*?\)/', ' ', strtoupper((string) $flightNumber));

        // Airline code = 3-letter ICAO, OR a 2-char IATA code with at least one
        // letter (letter+letter BA, letter+digit U2, or digit+letter 4U), then
        // the flight number (leading zeros stripped) and an optional suffix.
        if (! preg_match('/([A-Z]{3}|[A-Z]\d|\d[A-Z]|[A-Z]{2})\s*0*(\d{1,4})([A-Z]?)/', $raw, $m)) {
            return null;
        }

        $code = $m[1];
        $icaoToIata = self::icaoToIata();
        $iataToIcao = array_flip($icaoToIata);

        if (isset($icaoToIata[$code])) {          // gave us the ICAO code
            $iata = $icaoToIata[$code];
            $icao = $code;
        } elseif (isset($iataToIcao[$code])) {    // gave us a known IATA code
            $iata = $code;
            $icao = $iataToIcao[$code];
        } else {                                  // unknown carrier
            $iata = $code;
            $icao = null;
        }

        return ['iata' => $iata, 'icao' => $icao, 'number' => $m[2], 'suffix' => $m[3]];
    }

    /**
     * The tidy IATA flight code (e.g. "QR027 (Qatar Airways)" → "QR27", easyJet
     * "U22366" → "U22366"), leading zeros stripped, extra text ignored. '' when
     * there's no recognisable flight code.
     */
    public static function cleanFlightCode(?string $flightNumber): string
    {
        $p = self::parseFlight($flightNumber);

        return $p ? $p['iata'].$p['number'].$p['suffix'] : '';
    }

    /**
     * Flightradar24's /data/flights/ URL wants a clean airline code + number with
     * no leading zeros. It can't parse a 2-char IATA code that contains a DIGIT
     * (easyJet U2, Wizz W6) — for those we use the airline's all-letter ICAO
     * CALLSIGN (EZY, WZZ), which is what FR24 tracks the flight under. Plain
     * letter IATA codes (BA, VS) already load, so they're left as-is.
     */
    public static function normaliseFlightNumberForFr24(?string $flightNumber): string
    {
        $p = self::parseFlight($flightNumber);
        if ($p) {
            $code = (preg_match('/\d/', $p['iata']) && $p['icao']) ? $p['icao'] : $p['iata'];

            return strtolower($code.$p['number'].$p['suffix']);
        }

        // Unrecognised format → a clean slug of whatever we were given.
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $flightNumber));
    }

    /** Google live flight-status search (shows the status card) for a flight number. */
    public static function flightSearchLink(?string $flightNumber): ?string
    {
        // Use the clean code ("QR27") where we can recognise it, so Google gets a
        // tidy query instead of "QR027(QATARAIRWAYS)".
        $fn = self::cleanFlightCode($flightNumber)
            ?: strtoupper(preg_replace('/\s+/', '', (string) $flightNumber));

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
     * Free-form notes the office adds for the DRIVER (extra info the customer
     * gave — "call on arrival", "side entrance", etc.). Stored in meta so an ETO
     * re-import or calendar sync never wipes it. Shown on the driver's job screen.
     */
    public function driverNotes(): ?string
    {
        $notes = trim((string) ($this->meta['driver_notes'] ?? ''));

        return $notes !== '' ? $notes : null;
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
     * Words in a payment line that mean money is (or may be) owed on the day —
     * a deposit was paid, a balance is pending, cash is due, and so on. Used by
     * the failsafe so the driver is never wrongly told "collect nothing".
     */
    private const OUTSTANDING_SIGNAL = '/deposit|balance|outstanding|remaining|owing|owed|\bcash\b|to collect|\bcollect\b|to pay|part[ -]?paid|\bdue\b|pending/i';

    /** All payment text we hold, lower-cased and joined, for signal scanning. */
    private function paymentBlob(): string
    {
        return trim(strtolower(implode("\n", $this->paymentLines())));
    }

    /**
     * There is a sign money is owed on the day that we could NOT turn into a
     * definite cash figure (a truncated "Deposit £10 Paid", a bare "Balance
     * Pending", the word "cash" with no readable amount…). In that case the
     * driver must confirm with the office — we must never say "collect nothing".
     */
    public function paymentNeedsChecking(): bool
    {
        // A known cash amount, or the office/ business having settled it, means
        // there's nothing uncertain to check.
        if ($this->hasCashToCollect() || $this->businessCollectedCash()) {
            return false;
        }

        $blob = $this->paymentBlob();
        if ($blob === '') {
            // A cash-method job with no readable fare still isn't "nothing".
            return ($this->payment_method?->value ?? null) === 'cash'
                && ($this->final_price ?? $this->quoted_price) === null;
        }

        return (bool) preg_match(self::OUTSTANDING_SIGNAL, $blob)
            && ! $this->paymentLooksFullySettled();
    }

    /**
     * Confident the fare is fully settled: a paid/settled word with NO
     * outstanding-balance language anywhere, or an account (invoiced) job with
     * none. Deliberately strict — anything that hints at a balance fails this,
     * so "collect nothing" is only ever shown when it's genuinely safe.
     */
    public function paymentLooksFullySettled(): bool
    {
        if ($this->businessCollectedCash()) {
            return true;
        }
        $blob = $this->paymentBlob();
        if ($blob !== '' && preg_match(self::OUTSTANDING_SIGNAL, $blob)) {
            return false; // any outstanding language → not confidently settled
        }
        // A cash-method job is never "confidently settled" off its own fields —
        // only the override / business-collected above can settle it.
        if (($this->payment_method?->value ?? null) === 'cash') {
            return false;
        }

        return $this->payment_status === 'paid'
            || ($this->payment_method?->value ?? null) === 'account'
            || ($blob !== '' && (bool) preg_match('/\bpaid\b|settled|prepaid/i', $blob));
    }

    /**
     * What the driver must physically COLLECT from the customer on this job.
     * Prefers the office-curated calendar Payment line (e.g. "Deposit £10 Paid
     * – £110 Cash Due"); otherwise derives a cash amount for an unpaid cash job.
     * Never claims "collect nothing" while any payment source hints a balance is
     * owed — it says "check with the office" instead (the failsafe).
     */
    public function driverCollectLine(): ?string
    {
        // 1) A definite cash amount → show it.
        $cash = $this->cashDueToDriver();
        if ($cash !== null && $cash > 0.001) {
            // Clean amount: "£130" not "£130.00", but keep pennies when present.
            $amount = rtrim(rtrim(number_format($cash, 2), '0'), '.');

            return '£'.$amount.' to collect (cash)';
        }

        // 2) FAILSAFE: never tell a driver "collect nothing" when money might be
        //    owed. If any payment source shows an outstanding balance we couldn't
        //    turn into a number (a truncated "Deposit £10 Paid", a bare "Balance
        //    Pending", the word "cash", etc.) tell them to CHECK — do not claim
        //    it's paid. This is what stops the "cash job says paid" error dead.
        if ($this->paymentNeedsChecking()) {
            return 'Payment may be due — check the amount with the office';
        }

        // 3) Only say "collect nothing" when we're genuinely confident it's paid.
        if ($this->businessCollectedCash() || $this->paymentLooksFullySettled()) {
            return 'Paid — collect nothing';
        }

        // 4) Nothing known → say nothing (no false reassurance).
        return null;
    }

    /** True when the driver has cash to physically collect from the customer. */
    public function hasCashToCollect(): bool
    {
        $cash = $this->cashDueToDriver();

        return $cash !== null && $cash > 0.001;
    }

    /** Just the cash amount to collect, formatted "£130" (or null if none). */
    public function cashToCollectDisplay(): ?string
    {
        $cash = $this->cashDueToDriver();
        if ($cash === null || $cash <= 0.001) {
            return null;
        }

        return '£'.rtrim(rtrim(number_format($cash, 2), '0'), '.');
    }

    /**
     * The driver has seen and OK'd the "collect the cash" reminder for the amount
     * currently due. If the amount later changes, this returns false again so the
     * driver is re-reminded for the new figure.
     */
    public function cashCollectAcknowledged(): bool
    {
        $ack = $this->meta['cash_ack'] ?? null;
        if (! $ack || ! isset($ack['amount'])) {
            return false;
        }
        $cash = $this->cashDueToDriver();

        return $cash !== null && abs((float) $ack['amount'] - $cash) < 0.01;
    }

    /** Record the driver acknowledging they'll collect the cash on this job. */
    public function acknowledgeCashCollect(?User $by = null): void
    {
        $cash = $this->cashDueToDriver();
        if ($cash === null || $cash <= 0.001) {
            return;
        }
        $meta = $this->meta ?? [];
        $meta['cash_ack'] = [
            'amount' => round($cash, 2),
            'at' => now()->toIso8601String(),
            'by' => $by?->id,
        ];
        $this->forceFill(['meta' => $meta])->save();
    }

    /** When the driver acknowledged the cash reminder, or null. */
    public function cashCollectAckAt(): ?\Illuminate\Support\Carbon
    {
        $at = $this->meta['cash_ack']['at'] ?? null;

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /**
     * ---- Driver job offer ---------------------------------------------------
     * A short brief the office can send to a driver to OFFER the job — the vital
     * info plus "Fare to you". The fare is the driver's pay (driverPay), set in
     * the payroll section, so the offer and the payroll figure always match. If
     * the pay isn't set yet the amount is left blank on purpose.
     */

    /** The "Fare to you" line value, e.g. "£130 Cash", or a blank placeholder. */
    public function driverOfferFare(): string
    {
        $pay = $this->driverPay();
        $method = $this->hasCashToCollect() ? 'Cash' : 'Bank transfer';
        if ($pay === null) {
            return '£____'; // set the driver's pay to fill this in
        }

        return '£'.rtrim(rtrim(number_format($pay, 2), '0'), '.').' '.$method;
    }

    /** Luggage line for the offer, appending a pram/buggy etc. spotted in notes. */
    private function offerLuggageLine(): ?string
    {
        // Keep the office's own wording ("2 Suitcases"), not the normalised
        // "2 bags" — prefer the calendar/booking text, fall back to the breakdown.
        $luggage = trim((string) ($this->calendarField('Luggage')
            ?: ($this->meta['luggage_text'] ?? '')
            ?: $this->luggage
            ?: $this->luggageBreakdown()));
        $haystack = strtolower(($this->special_requests ?? '').' '.($this->meta['luggage_text'] ?? ''));
        $extras = [];
        foreach (['pram' => 'Pram', 'buggy' => 'Buggy', 'pushchair' => 'Pushchair', 'stroller' => 'Stroller', 'wheelchair' => 'Wheelchair', 'golf' => 'Golf clubs'] as $kw => $label) {
            if (str_contains($haystack, $kw) && ! str_contains(strtolower($luggage), strtolower($label))) {
                $extras[] = $label;
            }
        }
        $parts = array_filter(array_merge([$luggage !== '' ? $luggage : null], $extras));

        return $parts ? implode(' + ', $parts) : null;
    }

    /** Child-seat line for the offer (customer's own, or the seats we provide). */
    private function offerChildSeatLine(): ?string
    {
        $notes = strtolower(($this->special_requests ?? '').' '.($this->meta['notes'] ?? ''));
        if (preg_match('/own (car ?)?seat|bringing (a |their )?(own )?(car ?)?seat/i', $notes)) {
            return 'Customer has own car seat';
        }

        return $this->displayChildSeats() ?: null;
    }

    /**
     * The full "Job Available" offer message for a driver — vital info + the fare
     * to the driver. Ready to copy or send on WhatsApp.
     */
    public function driverOfferMessage(): string
    {
        $lines = ['Job Available – '.($this->pickup_at?->format('d/m/y') ?? '')];
        $lines[] = '';

        $pickup = $this->displayPickupAddress() ?: $this->pickup_address;
        $dropoff = $this->displayDropoffAddress() ?: $this->destination_address;
        $lines[] = '📍 '.trim((string) $pickup).' → '.trim((string) $dropoff);

        if ($this->pickup_at) {
            // "08:00 am" style.
            $lines[] = '🕒 Pickup: '.$this->pickup_at->format('h:i a');
        }

        $pax = (int) $this->passengerCount();
        if ($pax > 0) {
            $lines[] = '👥 '.$pax.' '.\Illuminate\Support\Str::plural('Passenger', $pax);
        }

        if ($luggage = $this->offerLuggageLine()) {
            $lines[] = '🧳 '.$luggage;
        }
        if ($seat = $this->offerChildSeatLine()) {
            $lines[] = '👶 '.$seat;
        }
        if ($vehicle = $this->displayVehicleType()) {
            $lines[] = '🚐 '.$vehicle;
        }

        $lines[] = '💷 Fare to you: '.$this->driverOfferFare();

        return implode("\n", $lines);
    }

    /**
     * Whether the DRIVER has been paid in full for this job — drives the flag on
     * the bookings list so the office can see who still needs paying without
     * opening each booking. True for a cash job the customer settled directly, or
     * a pay figure that's been fully recorded as paid.
     */
    public function driverFullyPaid(): bool
    {
        if ($this->driverSettledByCustomer()) {
            return true; // cash — the driver already has it
        }

        $pay = $this->driverPay();

        return $pay !== null && ($this->driverPayRemaining() ?? 1) <= 0;
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

    /**
     * Every "Payment" line held for this booking: from ALL calendar-event rows
     * (a booking can have more than one, with differing text) and the booking's
     * own stored payment_text. Reading every source — not just one arbitrary
     * calendar row — is what makes the driver's collect amount deterministic and
     * safe: a stale/duplicate "Paid" copy can never hide a real "Cash Due" line.
     *
     * @return string[]
     */
    public function paymentLines(): array
    {
        $lines = [];
        foreach ($this->calendarEvents as $event) {
            $v = $event->descriptionValue('Payment');
            if (filled($v)) {
                $lines[] = $v;
            }
        }
        if (filled($this->meta['payment_text'] ?? null)) {
            $lines[] = $this->meta['payment_text'];
        }

        return $lines;
    }

    /**
     * The cash the driver physically collects from the customer on the day, as a
     * number (e.g. 90.00 from "Deposit £10 Paid – £90 Cash Due"). Null when the
     * job is fully prepaid / card, i.e. there's nothing for the driver to collect.
     *
     * DETERMINISTIC & SAFE: it scans every payment source we hold and, when they
     * disagree, always favours COLLECTING (an explicit cash figure from any
     * source wins; the largest is taken) — so opening the link twice can never
     * flip between "Paid" and a cash amount, and it never under-collects.
     */
    public function cashDueToDriver(): ?float
    {
        // An office-confirmed/corrected amount always wins over everything else.
        // (This is how a genuinely fully-prepaid cash job is set to £0.)
        $override = $this->meta['payroll']['cash_collected'] ?? null;
        if ($override !== null) {
            return (float) $override;
        }

        // The business took the money itself (e.g. card tapped in the car), so the
        // driver collects nothing from the customer.
        if ($this->businessCollectedCash()) {
            return null;
        }

        $isCashJob = ($this->payment_method?->value ?? null) === 'cash';
        $fare = $this->final_price ?? $this->quoted_price;

        // Parse every payment line we hold, gathering any explicit cash-to-collect
        // figures and any deposit figures.
        $cashAmounts = [];
        $deposits = [];
        foreach ($this->paymentLines() as $line) {
            // A bare "£320 Balance Pending" is NOT cash (may be a card balance);
            // an explicit CASH/collect word is required.
            if (preg_match('/£\s?([\d,]+(?:\.\d{1,2})?)\s*(?:cash due|cash|to collect|collect)/i', $line, $m)
                || preg_match('/(?:cash|collect)[^£]*£\s?([\d,]+(?:\.\d{1,2})?)/i', $line, $m)) {
                $cashAmounts[] = (float) str_replace(',', '', $m[1]);
            }
            if (preg_match('/deposit\D*£\s?([\d,]+(?:\.\d{1,2})?)/i', $line, $dep)) {
                $deposits[] = (float) str_replace(',', '', $dep[1]);
            }
        }

        // 1) An explicit cash figure in ANY source → take the largest. Never let
        //    a stale "Paid" copy make the driver under-collect.
        if ($cashAmounts) {
            return max($cashAmounts);
        }

        // 2) Deposit paid on a CASH job with a known fare → the balance is cash:
        //    fare − the smallest deposit seen (i.e. the most that could be owed).
        //    Covers a wrapped/truncated line where the cash amount was lost.
        if ($isCashJob && $fare && $deposits) {
            $deposit = min($deposits);
            if ((float) $fare > $deposit) {
                return round((float) $fare - $deposit, 2);
            }
        }

        // 3) It IS a cash job → the driver collects cash. Default to the whole
        //    fare when nothing more specific was found. NOT gated on
        //    payment_status: a deposit-paid cash job is wrongly stamped "paid" on
        //    import, and that must never read as "collect nothing". If a cash job
        //    was genuinely prepaid in full, the office sets the confirmed cash to
        //    £0 (the override above), which is the only thing that suppresses it.
        if ($isCashJob && $fare) {
            return (float) $fare;
        }

        // 4) Non-cash job with no cash figure anywhere → nothing to collect.
        return null;
    }

    /**
     * The rare 0.01%: a job set up as cash where the customer actually paid the
     * BUSINESS by card (e.g. tapped a card in the car). The business collected
     * the money, so it owes the driver their pay — this job is NOT settled with
     * the driver. Set by the office from the booking's payroll section.
     */
    public function businessCollectedCash(): bool
    {
        return (bool) ($this->meta['payroll']['company_collected'] ?? false);
    }

    /** The office has eyeballed/confirmed the cash the driver collects. */
    public function cashConfirmed(): bool
    {
        return (bool) ($this->meta['payroll']['cash_confirmed'] ?? false);
    }

    /**
     * Cash jobs settle themselves: the customer hands the balance straight to the
     * driver, so the driver already has their pay and the BUSINESS owes nothing.
     * Covers pure-cash jobs AND part-deposit/cash jobs ("… £90 Cash Due"). Only a
     * job paid entirely by CARD/account to the company leaves the business owing —
     * including a cash job the office has flagged as paid-by-card-to-the-business.
     */
    public function driverSettledByCustomer(): bool
    {
        if ($this->businessCollectedCash()) {
            return false;
        }

        return ($this->payment_method?->value ?? null) === 'cash'
            || $this->cashDueToDriver() !== null;
    }

    /**
     * What the BUSINESS still owes the driver for this job (never negative).
     * For cash jobs this is always 0 — the customer paid the driver directly,
     * nothing is sent from the business.
     */
    public function driverPayRemaining(): ?float
    {
        $pay = $this->driverPay();
        if ($pay === null) {
            return null;
        }
        if ($this->driverSettledByCustomer()) {
            return 0.0;
        }

        return max(0, round($pay - $this->driverPaidAmount(), 2));
    }

    /**
     * What this job costs the business in driver earnings — for the profit view.
     * On a cash job the driver keeps the cash they collect, so THAT is the cost
     * (plus any extra the business pays on top); on a card/account job the cost
     * is just the driver pay the business hands over. So fare − driverCost lands
     * on the business's real margin (a cash job nets the deposit it kept).
     */
    public function driverCost(): float
    {
        $pay = $this->driverPay() ?? 0.0;

        if ($this->driverSettledByCustomer()) {
            return round(($this->cashDueToDriver() ?? 0.0) + $pay, 2);
        }

        return round($pay, 2);
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

    /**
     * The name payroll groups by — always the driver's FULL name so the same
     * person can't split into two groups. A linked driver gives it directly; a
     * job tagged only with a callsign/first name (e.g. "Abdi") is resolved to
     * the matching system driver's full name ("Abdirazak Hassan") so their jobs
     * total together. (Customer messages still show the short name — separate.)
     */
    public function payrollDriverName(): string
    {
        if ($this->driver) {
            return $this->driver->name;
        }

        $manual = trim((string) ($this->meta['driver_details']['name'] ?? ''));
        if ($manual === '') {
            return 'Unassigned';
        }

        return static::resolveDriverFullName($manual) ?? $manual;
    }

    /**
     * Map a callsign / first name / full name onto a system driver's FULL name,
     * or null when there's no single clear match (so ambiguous names aren't
     * wrongly merged). Memoised per request via the container.
     */
    public static function resolveDriverFullName(string $name): ?string
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return null;
        }

        return static::driverNameMap()[$key] ?? null;
    }

    /** The {alias → full name} lookup, memoised per request. */
    public static function driverNameMap(): array
    {
        return app()->has('cet.driverNameMap')
            ? app('cet.driverNameMap')
            : app()->instance('cet.driverNameMap', static::buildDriverNameMap());
    }

    /**
     * Every name string that resolves to this driver's full name — the full
     * name plus any callsign / first name / login local-part that maps to it.
     * Lets a bookings filter catch exactly the jobs payroll groups under a
     * driver, callsign-tagged ones included.
     *
     * @return array<int, string> lower-cased
     */
    public static function driverNameAliases(string $fullName): array
    {
        $full = mb_strtolower(trim($fullName));
        $aliases = [$full];
        foreach (static::driverNameMap() as $key => $mapsTo) {
            if (mb_strtolower($mapsTo) === $full) {
                $aliases[] = $key;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Build the lookup of {callsign|first name|full name} → full name across all
     * system drivers. A key that would point at two different drivers is dropped
     * so we never merge two people who happen to share a first name.
     *
     * @return array<string, string>
     */
    protected static function buildDriverNameMap(): array
    {
        $map = [];
        $ambiguous = [];

        User::query()->whereHas('driverProfile')->with('driverProfile')->get()
            ->each(function (User $d) use (&$map, &$ambiguous) {
                $full = $d->name;
                $local = mb_strtolower(\Illuminate\Support\Str::before((string) $d->email, '@'));
                $keys = array_filter([
                    mb_strtolower(trim((string) $d->driverProfile?->callsign)),
                    mb_strtolower(\Illuminate\Support\Str::before(trim($d->name), ' ')),
                    mb_strtolower(trim($d->name)),
                    ctype_alpha($local) ? $local : '',
                ]);
                foreach (array_unique($keys) as $k) {
                    if (isset($map[$k]) && $map[$k] !== $full) {
                        $ambiguous[$k] = true;
                    } else {
                        $map[$k] = $full;
                    }
                }
            });

        foreach (array_keys($ambiguous) as $k) {
            unset($map[$k]);
        }

        return $map;
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
