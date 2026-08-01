<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Quote;
use App\Models\VehicleType;
use App\Services\BookingService;
use App\Services\BookingStatusService;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BookingStatusService $status,
        private readonly BookingNotifier $notifier,
        private readonly \App\Services\Calendar\GoogleCalendarService $google,
        private readonly \App\Services\Calendar\CalendarTimeSync $timeSync,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Drivers never see the bookings area — their world is My jobs, which
        // shows only their own assigned work (and no prices).
        if ($user->isDriver() && ! $user->isAdmin()) {
            return redirect()->route('driver.jobs');
        }

        // Sticky memory: remember the last time-filter + status the operator
        // chose, and restore them when they land on /bookings bare (from the nav)
        // by redirecting to the canonical URL so pagination/links stay consistent.
        if ($request->filled('filter')) {
            session(['bookings.filter' => $request->query('filter')]);
        }
        if ($request->has('status')) {
            session(['bookings.status' => (string) $request->query('status')]);
        }
        if (! $request->hasAny(['filter', 'month', 'q', 'status', 'page', 'from', 'to', 'by', 'driver', 'payment', 'ran', 'vehicle'])
            && (session('bookings.filter') || session('bookings.status'))) {
            return redirect()->route('bookings.index', array_filter([
                'filter' => session('bookings.filter'),
                'status' => session('bookings.status') ?: null,
            ]));
        }

        ['query' => $query, 'q' => $q, 'month' => $month, 'filter' => $filter, 'statusFilter' => $statusFilter, 'driverName' => $driverName]
            = $this->applyBookingFilters($request);

        $bookings = $query->paginate(30)->withQueryString();

        // Remember this exact list view so a booking page can send the operator
        // straight back to where they were (same month/filter/search/page).
        session(['bookings.return_url' => $request->fullUrl()]);

        return view('bookings.index', [
            'bookings' => $bookings,
            'q' => $q,
            'filter' => $filter,
            'month' => $month,
            'statusFilter' => $statusFilter,
            'driverName' => $driverName ?: null,
            'attention' => $this->attentionItems($request, $q, $month, $statusFilter),
        ]);
    }

    /**
     * Build the bookings query from the request (search + time/month scope +
     * status filter). Shared by the list and the CSV export so the two never
     * disagree. Returns the query plus the resolved context for the view.
     *
     * @return array{query: \Illuminate\Database\Eloquent\Builder, q: string, month: ?Carbon, filter: string, statusFilter: ?string}
     */
    private function applyBookingFilters(Request $request): array
    {
        $user = $request->user();

        $query = Booking::with(['customer', 'vehicleType', 'driver', 'corporateAccount', 'calendarEvent']);

        // Corporate clients only ever see their own account's bookings.
        if ($user->isCorporateClient()) {
            $query->whereIn('corporate_account_id', $user->corporateAccounts->pluck('id'));
        }

        // Search — reference, ETO reference, customer name/phone, lead passenger.
        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('reference', 'like', "%{$q}%")
                    ->orWhere('external_reference', 'like', "%{$q}%")
                    ->orWhere('meta->lead_name', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"));
            });
        }

        // Driver filter (from the Profit page) — every job that payroll groups
        // under this driver, matching the assigned driver's name OR a callsign /
        // manual name that resolves to it, so the list matches the commission count.
        $driverName = trim((string) $request->query('driver'));
        if ($driverName !== '') {
            $aliases = Booking::driverNameAliases($driverName);
            $query->where(function ($sub) use ($driverName, $aliases) {
                $sub->whereHas('driver', fn ($d) => $d->where('name', $driverName));
                foreach ($aliases as $alias) {
                    $sub->orWhereRaw("lower(json_extract(meta, '$.driver_details.name')) = ?", [$alias]);
                }
            });
        }

        // A date RANGE from the Review page — from/to over either the trip date
        // (by=pickup, default) or the booked/created date (by=created). ran=1
        // keeps only jobs that have already run (the "Completed" lens). This is
        // how every figure on the Review page drills through to its exact list.
        $from = $request->date('from');
        $to = $request->date('to');
        $rangeField = $request->query('by') === 'created' ? 'created_at' : 'pickup_at';
        $rangeMode = (bool) ($from || $to);

        // A specific MONTH view (YYYY-MM) — every booking that month, for payroll
        // and month-end checks. Takes precedence over the quick tabs.
        $monthParam = (string) $request->query('month');
        $month = preg_match('/^\d{4}-\d{2}$/', $monthParam)
            ? Carbon::createFromFormat('Y-m', $monthParam, config('app.timezone'))->startOfMonth()
            : null;

        if ($rangeMode) {
            $start = ($from ?? $to)->copy()->startOfDay();
            $end = ($to ?? $from)->copy()->endOfDay();
            $query->whereBetween($rangeField, [$start, $end]);
            if ($request->boolean('ran')) {
                $query->where('pickup_at', '<=', now()); // only jobs that have run
            }
            // Order by the field being ranged on: a "came in" (created) view lists
            // newest booking first (the order they came in); a pickup view by trip.
            $query->orderByDesc($rangeField);
            $filter = 'range';
        } elseif ($month) {
            $query->whereBetween('pickup_at', [$month, $month->copy()->endOfMonth()])->orderBy('pickup_at');
            $filter = 'month';
        } else {
            // Time filter for order. Default to Upcoming (soonest first); when
            // searching, default to All so a match isn't hidden by its date.
            $filter = $request->query('filter') ?: ($q !== '' ? 'all' : 'upcoming');
            match ($filter) {
                'today' => $query->whereBetween('pickup_at', [now()->startOfDay(), now()->endOfDay()])->orderBy('pickup_at'),
                // Bookings that CAME IN today (created today), for the dashboard
                // tile — listed in pickup-date order so the day headers read top-down.
                'booked-today' => $query->whereDate('created_at', today())->orderBy('pickup_at'),
                'past' => $query->where('pickup_at', '<', now()->startOfDay())->orderByDesc('pickup_at'),
                'all' => $query->orderByDesc('pickup_at'),
                default => $query->where('pickup_at', '>=', now()->startOfDay())->orderBy('pickup_at'), // upcoming
            };
        }

        // Status filter (orthogonal to the time/month scope): pick a single
        // status to view, e.g. just cancelled or just completed. With none
        // chosen we HIDE cancelled — a cancelled job isn't a journey that ran,
        // so it must not inflate the count. (No-show is kept: it still happened.)
        $status = $request->query('status');
        $statusFilter = in_array($status, BookingStatus::values(), true) ? $status : null;
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        } elseif ($rangeMode) {
            // Range drill-through matches the Review figures, which exclude BOTH
            // cancelled and no-show (neither is revenue/a journey that ran).
            $query->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value]);
        } elseif ($q === '') {
            // Hide cancelled only in the browse view; a search should still find
            // a cancelled booking when you're looking one up by name/reference.
            $query->where('status', '!=', BookingStatus::Cancelled->value);
        }

        // Vehicle-type filter (from the Review "revenue by vehicle" table).
        $vehicle = (int) $request->query('vehicle');
        if ($vehicle > 0) {
            $query->where('vehicle_type_id', $vehicle);
        }

        // Payment split (from the Review page): paid vs still-owing.
        $payment = $request->query('payment');
        if ($payment === 'paid') {
            $query->where('payment_status', 'paid');
        } elseif ($payment === 'unpaid') {
            $query->where('payment_status', '!=', 'paid');
        }

        return compact('query', 'q', 'month', 'filter', 'statusFilter', 'driverName');
    }

    /**
     * Ops "needs attention" pins for the top of the list (admins only, on the
     * live browse view): jobs unallocated within 2h of pickup, flagged by the
     * ETO audit, or looking like a duplicate. Bounded to the next few days.
     *
     * @return \Illuminate\Support\Collection<int, array{booking: Booking, reasons: array<int, string>}>
     */
    private function attentionItems(Request $request, string $q, ?Carbon $month, ?string $statusFilter): \Illuminate\Support\Collection
    {
        // Only on the plain live view, and only for admins.
        if ($q !== '' || $month || $statusFilter || ! $request->user()->isAdmin()) {
            return collect();
        }

        return Booking::with(['customer', 'driver', 'vehicleType', 'airport'])
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->whereBetween('pickup_at', [now(), now()->addDays(3)])
            ->orderBy('pickup_at')
            ->limit(80)
            ->get()
            ->map(function (Booking $b) {
                $reasons = [];
                if (! $b->driver_id && $b->pickup_at->lte(now()->addHours(2))) {
                    $reasons[] = 'unallocated';
                }
                if (! empty($b->meta['audit_issues'])) {
                    $reasons[] = 'audit';
                }
                if ($b->looksDuplicated()) {
                    $reasons[] = 'duplicate';
                }

                return ['booking' => $b, 'reasons' => $reasons];
            })
            ->filter(fn ($x) => $x['reasons'] !== [])
            ->take(10)
            ->values();
    }

    /**
     * CSV export of exactly the current list view (same month/filter/status/
     * search). For payroll and accounts — one button, one file.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        ['query' => $query, 'month' => $month, 'filter' => $filter] = $this->applyBookingFilters($request);

        $tag = $month ? $month->format('Y-m') : ($filter ?: 'list');
        $filename = 'cet-bookings-'.$tag.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Time', 'Ref', 'ETO Ref', 'Customer', 'Pickup', 'Drop-off',
                'Vehicle', 'Driver', 'Pax', 'Status', 'Fare', 'Payment']);

            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $b) {
                    fputcsv($out, [
                        $b->pickup_at?->format('Y-m-d'),
                        $b->pickup_at?->format('H:i'),
                        $b->reference,
                        $b->external_reference,
                        $b->displayCustomerName(),
                        $b->displayPickupAddress(),
                        $b->displayDropoffAddress(),
                        $b->displayVehicleType(),
                        $b->driver?->name ?? ($b->meta['driver_details']['name'] ?? 'Unassigned'),
                        $b->passengerCount(),
                        $b->status->label(),
                        $b->fareAmount(),
                        $b->payment_method?->label(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function create(Request $request): View
    {
        // Optionally prefill from an AI quote (quote=ID) so a quote converts to
        // a confirmed booking in seconds.
        $quote = $request->filled('quote')
            ? Quote::with('vehicleType')->find($request->integer('quote'))
            : null;

        // Optionally prefill from a customer (rebook=… from the CRM).
        $customer = $request->filled('customer')
            ? \App\Models\Customer::with('preferredVehicleType')->find($request->integer('customer'))
            : null;

        return view('bookings.create', $this->formData($request) + ['quote' => $quote, 'customer' => $customer]);
    }

    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $booking = $this->bookings->createFromForm($request->validated(), $request->user());

        // Link the originating quote, if this booking came from one.
        if ($request->filled('quote_id')) {
            Quote::where('id', $request->integer('quote_id'))
                ->whereNull('converted_booking_id')
                ->update(['converted_booking_id' => $booking->id]);
        }

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} created successfully.");
    }

    public function show(Request $request, Booking $booking): View|RedirectResponse
    {
        // Drivers never see the full booking (prices, payment, comms) — they get
        // the driver job screen, and only for their own job.
        if ($request->user()->isDriver() && ! $request->user()->isAdmin()) {
            return $booking->driver_id === $request->user()->id
                ? redirect()->route('driver.job', $booking)
                : redirect()->route('driver.jobs');
        }

        // Authorisation: corporate clients can only view their own bookings.
        if ($request->user()->isCorporateClient()
            && ! $request->user()->corporateAccounts->pluck('id')->contains($booking->corporate_account_id)) {
            abort(403);
        }

        // Auto-follow the live calendar: when Google Calendar is connected, reading
        // this booking silently matches its time to the live event (the operator's
        // source of truth) so a time changed directly on Google shows here without
        // any button. Read-only — it never writes to Google. A no-op until the
        // credentials are configured on the server, and failures are swallowed so
        // the page always renders.
        $this->autoFollowCalendar($booking);

        return view('bookings.show', $this->showData($request, $booking));
    }

    /**
     * If the live Google Calendar is reachable, scan this booking against it so
     * the page reflects the calendar exactly — including linking an ETO import
     * to its event by reference on first view, then mirroring the time and the
     * whole details block. Silent and best-effort: skipped when the calendar
     * isn't connected, and any error leaves our stored copy untouched so the
     * page always renders. The expensive match runs once per booking; after
     * that it's a fast single-event read.
     */
    private function autoFollowCalendar(Booking $booking): void
    {
        if (! $this->google->configured() || ! $this->google->active()) {
            return; // not connected — the manual "Scan calendar" button still works
        }

        $linked = filled($booking->calendarEvent?->google_event_id);
        if (! $linked) {
            // Only auto-SEARCH for bookings likely to be on the calendar (they
            // carry a reference), and at most once every few minutes, so an
            // unmatchable booking doesn't hit the API on every page view. The
            // manual "Scan calendar" button always searches on demand.
            if (blank($booking->external_reference)) {
                return;
            }
            $lastTry = $booking->meta['calendar_scan_attempted_at'] ?? null;
            if ($lastTry && \Illuminate\Support\Carbon::parse($lastTry)->gt(now()->subMinutes(10))) {
                return;
            }
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
                'calendar_scan_attempted_at' => now()->toDateTimeString(),
            ])])->save();
        }

        try {
            $this->timeSync->scan($booking);
        } catch (\Throwable $e) {
            // Never let a calendar read break the booking page.
            \Illuminate\Support\Facades\Log::warning('Auto-follow calendar scan failed', [
                'booking' => $booking->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function edit(Request $request, Booking $booking): View|RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($booking->status->isTerminal()) {
            return redirect()
                ->route('bookings.show', $booking)
                ->with('status', 'This booking is '.$booking->status->label().' and can no longer be edited.');
        }

        $booking->load(['customer', 'stops']);

        return view('bookings.edit', $this->formData($request) + ['booking' => $booking]);
    }

    public function update(UpdateBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->bookings->updateFromForm($booking, $request->validated());

        // Adding a contact number can make masking possible on an already-
        // allocated job — open the line now so the office can hand it out.
        // Idempotent, and a no-op for admin drivers, unmasked jobs, missing
        // numbers or when masking is off.
        $booking->refresh()->loadMissing('customer', 'driver');
        if ($booking->driver && ! $booking->status->isTerminal()) {
            app(\App\Services\Telephony\TwilioProxyService::class)->openSession($booking, $booking->driver);
        }

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} updated. The calendar isn't changed automatically — update the Google Calendar event yourself if needed.");
    }

    /**
     * Cancel a booking with a recorded reason. Uses the status engine so the
     * transition is validated, audited and side-effects fire (waiting list).
     * The calendar event is left in place — removing it from Google Calendar is
     * a deliberate manual step for the operator.
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->status->transition(
                $booking,
                BookingStatus::Cancelled,
                $request->user(),
                note: 'Cancelled: '.$data['cancellation_reason'],
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cancellation_reason' => $e->getMessage()]);
        }

        $booking->forceFill([
            'meta' => array_merge($booking->meta ?? [], [
                'cancellation_reason' => $data['cancellation_reason'],
                'cancelled_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} cancelled. Remember to remove it from Google Calendar if it was pushed there.");
    }

    /**
     * Merge a duplicate booking into this one. $booking is the copy we KEEP;
     * the duplicate is folded in (driver, tips, calendar link, any blank fields,
     * merged meta) and then removed — a "replace", not a bare delete. Only allowed
     * when the two are genuinely the same journey (same pickup minute + customer),
     * so a bad request can't merge two unrelated bookings. Calendar untouched.
     */
    public function merge(Request $request, Booking $booking, \App\Services\Bookings\BookingMerger $merger): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['dupe_id' => ['required', 'integer']]);

        $dupe = Booking::findOrFail($data['dupe_id']);
        if ($dupe->id === $booking->id) {
            throw ValidationException::withMessages(['dupe_id' => 'A booking cannot be merged into itself.']);
        }

        // Only merge a booking the page actually flagged as a same-journey twin,
        // so a stray request can never fold two unrelated jobs together.
        if (! $booking->duplicateCandidates()->contains('id', $dupe->id)) {
            throw ValidationException::withMessages([
                'dupe_id' => 'These bookings are not the same journey, so they were not merged.',
            ]);
        }

        $mergedRef = $dupe->external_reference ?: $dupe->reference;
        $merger->mergeAndDelete($booking, $dupe);

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Merged {$mergedRef} into this booking — one record now. Google Calendar was not touched.");
    }

    /**
     * Add an EXTRA driver/car to a multi-car job (e.g. a 3-car wedding). Each
     * gets its own shareable link and its own per-car status.
     */
    public function addExtraDriver(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'reg' => ['nullable', 'string', 'max:20'],
            'car' => ['nullable', 'string', 'max:80'],
        ]);

        $booking->addExtraDriver($data);

        return back()->with('status', "Added {$data['name']} as another car — copy their link below to send it.");
    }

    /** Remove an extra car from a multi-car job. */
    public function removeExtraDriver(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['token' => ['required', 'string']]);
        $booking->removeExtraDriver($data['token']);

        return back()->with('status', 'Removed that car from the job.');
    }

    /** Set pay / record a payment for ONE extra car — paid separately per car. */
    public function extraDriverPayroll(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'action' => ['required', 'in:set,record'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $car = $booking->extraDriver($data['token']);
        abort_if($car === null, 404);

        $amount = round((float) $data['amount'], 2);
        if ($data['action'] === 'set') {
            $booking->setExtraDriverPay($data['token'], $amount);
            $status = "Pay for {$car['name']} set to £".number_format($amount, 2).'.';
        } else {
            $booking->recordExtraDriverPayment($data['token'], $amount, $request->user()->name, $data['note'] ?? null);
            $status = '£'.number_format($amount, 2)." recorded as paid to {$car['name']}.";
        }

        return back()->with('status', $status);
    }

    /**
     * Mark a flagged pair as NOT a duplicate — two genuinely separate jobs that
     * just share a pickup time (e.g. two customers booked for the same minute).
     * Recorded on BOTH bookings so neither flags the other again, and keyed by
     * reference so it survives a re-import.
     */
    public function keepSeparate(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['dupe_id' => ['required', 'integer']]);
        $other = Booking::findOrFail($data['dupe_id']);
        if ($other->id === $booking->id) {
            throw ValidationException::withMessages(['dupe_id' => 'A booking cannot be separated from itself.']);
        }

        $booking->markNotDuplicateOf($other);
        $other->markNotDuplicateOf($booking);

        $ref = $other->external_reference ?: $other->reference;

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Marked {$ref} as a separate booking — these two won't be flagged as duplicates again.");
    }

    /** @return array<string, mixed> */
    /**
     * Toggle number masking for a single job. Turning it OFF frees both sides
     * to use their real numbers (handy on a return leg where they already have
     * each other's number) and closes any open masked line. Turning it back ON
     * reopens a fresh masked line for a live non-admin driver.
     */
    public function toggleMasking(Request $request, Booking $booking, \App\Services\Telephony\TwilioProxyService $proxy): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $off = ! $booking->maskingDisabled();
        $hasColumn = \Illuminate\Support\Facades\Schema::hasColumn('bookings', 'masking_disabled');

        // Apply to BOTH legs of a return journey so unmasking one doesn't leave
        // the other masked. Writes the durable column (when migrated) AND the
        // meta flag, so the setting sticks no matter what.
        $legs = Booking::query()
            ->where('id', $booking->id)
            ->orWhere('id', $booking->linked_booking_id)
            ->orWhere('linked_booking_id', $booking->id)
            ->get();

        foreach ($legs as $leg) {
            $attrs = ['meta' => array_merge($leg->meta ?? [], ['masking_disabled' => $off])];
            if ($hasColumn) {
                $attrs['masking_disabled'] = $off;
            }
            $leg->forceFill($attrs)->save();
        }

        if ($off) {
            foreach ($legs as $leg) {
                $proxy->closeSession($leg, 'masking disabled for job');
            }

            return back()->with('status', "Masking OFF for {$booking->reference} — both sides use their real numbers.");
        }

        // Re-mask: open a fresh line if there's a live, non-admin driver.
        if ($booking->driver && ! $booking->status->isTerminal() && ! $booking->driver->isAdmin()) {
            $proxy->openSession($booking->fresh(['customer', 'driver']), $booking->driver);
        }

        return back()->with('status', "Masking back ON for {$booking->reference}.");
    }

    /**
     * Per-booking masking timing: how many minutes BEFORE pickup the masked line
     * goes live, and how many hours AFTER drop-off it closes. Applies to both
     * legs of a return, and reflects the change straight away — opens the line if
     * we're now inside the window, or closes it if the window's been pulled in.
     */
    public function maskingTiming(Request $request, Booking $booking, \App\Services\Telephony\TwilioProxyService $proxy): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'lead_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'grace_hours' => ['required', 'numeric', 'min:0', 'max:48'],
        ]);

        $legs = Booking::query()
            ->where('id', $booking->id)
            ->orWhere('id', $booking->linked_booking_id)
            ->orWhere('linked_booking_id', $booking->id)
            ->get();

        foreach ($legs as $leg) {
            $leg->forceFill(['meta' => array_merge($leg->meta ?? [], [
                'masking_lead_minutes' => (int) $data['lead_minutes'],
                'masking_grace_hours' => (float) $data['grace_hours'],
            ])])->save();

            // The number is live from allocation — make sure the line is open (the
            // window only gates when calls CONNECT, handled at call time).
            $leg->refresh()->loadMissing('customer', 'driver');
            if ($leg->driver && ! $leg->status->isTerminal()) {
                $proxy->openSession($leg, $leg->driver);
            }
        }

        return back()->with('status', "Masking timing saved — calls connect from {$data['lead_minutes']} min before pickup, stop drop-off +{$data['grace_hours']}h.");
    }

    /**
     * Ask the assigned driver to share their location now: flag the request on
     * the booking and push their phone. Their job screen answers with a one-off
     * ping (works at any live stage, even before Set off).
     */
    public function requestLocation(Request $request, Booking $booking, \App\Services\Push\WebPushService $push): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (! $booking->driver_id || $booking->status->isTerminal()) {
            return response()->json(['ok' => false, 'reason' => 'no_live_driver'], 422);
        }

        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'location_request_at' => now()->toIso8601String(),
        ])])->save();

        $sent = $booking->driver
            ? $push->sendToUser(
                $booking->driver,
                'Location request',
                'The office needs your location — tap to share.',
                ['url' => route('driver.job', $booking), 'tag' => 'locreq-'.$booking->id],
            )
            : 0;

        return response()->json(['ok' => true, 'pushed' => $sent, 'requested_at' => now()->toIso8601String()]);
    }

    /**
     * JSON snapshot of the driver's latest position for this job + the pending
     * request state — polled by the booking page to update the live card.
     */
    public function locationData(Request $request, Booking $booking): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $loc = $booking->latestLocation();
        $requestedAt = $booking->locationRequestedAt();

        return response()->json([
            'has_driver' => (bool) $booking->driver_id,
            'requested_at' => $requestedAt?->toIso8601String(),
            'requested_age' => $requestedAt ? (int) abs(now()->diffInSeconds($requestedAt)) : null,
            'pending' => $booking->locationRequestPending(),
            'ping' => $loc ? [
                'lat' => (float) $loc->latitude,
                'lng' => (float) $loc->longitude,
                'heading' => $loc->heading !== null ? (float) $loc->heading : null,
                'captured_at' => $loc->captured_at->toIso8601String(),
                'age' => (int) abs(now()->diffInSeconds($loc->captured_at)),
            ] : null,
        ]);
    }

    private function showData(Request $request, Booking $booking): array
    {
        $booking->load([
            'customer', 'vehicleType', 'driver', 'stops', 'corporateAccount',
            'calendarEvent', 'statusHistory.changedBy', 'payments',
        ]);

        // Customer comms thread (admins only). Reminder wording is refreshed to
        // the current driver/vehicle so the "Send on WhatsApp" text is up to date.
        $messages = collect();
        if ($request->user()->isAdmin()) {
            // Backfill reminders for bookings that didn't go through the form
            // (e.g. ETO imports) so a reminder is always ready to send.
            if ($booking->pickup_at?->isFuture() && ! $booking->status->isTerminal()) {
                $this->notifier->ensureReminders($booking);
            }

            $messages = $booking->messages()->orderBy('created_at')->get();
            foreach ($messages as $m) {
                // Always show the current reminder wording — so a driver/passenger
                // set or changed AFTER it was marked sent is reflected in the
                // "Send on WhatsApp" text (you can re-send the updated version).
                if ($m->isReminder()) {
                    $m->body = $this->notifier->reminderBody($booking);
                } elseif ($m->isReviewRequest()) {
                    $m->body = $this->notifier->reviewBody($booking);
                }
            }
        }

        // Newest first, capped — the full history is rarely needed and made the
        // page very long. Show the latest handful; older entries stay in the DB.
        $auditLogs = $request->user()->isAdmin()
            ? $booking->auditLogs()->with('user')->latest('created_at')->limit(8)->get()
            : collect();
        $auditLogTotal = $request->user()->isAdmin() ? $booking->auditLogs()->count() : 0;

        // Drivers to prefill the "driver for this job" picker (admins only):
        // the cover-driver roster plus any system drivers not already in it.
        $jobDrivers = collect();
        if ($request->user()->isAdmin()) {
            $cover = \App\Models\CoverDriver::where('is_active', true)->orderBy('name')->get()
                ->map(fn ($d) => ['name' => $d->name, 'phone' => $d->phone, 'reg' => $d->vehicle_reg, 'car' => $d->vehicle]);

            $seen = $cover->map(fn ($d) => strtolower($d['name']))->all();
            $system = \App\Models\User::where('is_active', true)->whereHas('driverProfile')
                ->with('driverProfile.defaultVehicle')->orderBy('name')->get()
                ->map(fn ($d) => [
                    'name' => $d->driverProfile?->callsign ?: \Illuminate\Support\Str::before($d->name, ' '),
                    'phone' => $d->phone,
                    'reg' => $d->driverProfile?->defaultVehicle?->registration,
                    'car' => trim(implode(' ', array_filter([
                        $d->driverProfile?->defaultVehicle?->colour,
                        $d->driverProfile?->defaultVehicle?->make,
                        $d->driverProfile?->defaultVehicle?->model,
                    ]))),
                ])
                ->reject(fn ($d) => in_array(strtolower($d['name']), $seen, true));

            $jobDrivers = $cover->concat($system)->values();
        }

        // System drivers (with a login) that can be ALLOCATED to this job right
        // from the booking page — same allocate flow as the dispatch board.
        $allocatableDrivers = $request->user()->isAdmin()
            ? \App\Models\User::where('is_active', true)->whereHas('driverProfile')
                ->with('driverProfile.defaultVehicle')->orderBy('name')->get()
            : collect();

        // Whether the live calendar can be scanned for this booking — used to
        // show the "Scan calendar" button even for ETO imports that don't yet
        // have a stored event id (the scan matches them by reference).
        $canScan = $request->user()->isAdmin()
            && $this->google->configured() && $this->google->active();

        // Where "← Back to bookings" returns to — the last list view the operator
        // had open (their month/filter/search/page), falling back to the list.
        $backUrl = session('bookings.return_url', route('bookings.index'));

        return compact('booking', 'auditLogs', 'auditLogTotal', 'messages', 'jobDrivers', 'allocatableDrivers', 'canScan', 'backUrl');
    }

    /**
     * Set the driver details shown on this job's reminder — an existing driver
     * prefilled from the picker, or a third-party driver typed in by hand. Stored
     * on the booking so the WhatsApp reminder includes the "• Driver details"
     * block before the operator sends it.
     */
    public function setDriverDetails(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'reg' => ['nullable', 'string', 'max:16'],
            'car' => ['nullable', 'string', 'max:80'],
        ]);

        $booking->forceFill([
            'meta' => array_merge($booking->meta ?? [], [
                'driver_details' => array_filter([
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'reg' => $data['reg'] ? strtoupper($data['reg']) : null,
                    'car' => $data['car'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ]),
        ])->save();

        return back()->with('status', 'Driver details added — they now appear in the reminder below.');
    }

    /**
     * Payroll on a job (admin only): set what the job pays the driver, or
     * record money handed over — so the office always knows who's been paid,
     * how much, and what's still owed. Stored on the booking's meta (no
     * migration) with a timestamped history of every payment.
     */
    /**
     * Manually create a review request for a completed job — overrides the
     * once-per-customer rule, so the office can always ask when it wants to.
     */
    public function requestReview(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (blank($booking->customer?->phone)) {
            return back()->with('status', 'No phone number on file for this customer — add one, then request the review.');
        }
        if ($booking->messages()->where('type', 'review_request')->exists()) {
            return back()->with('status', 'A review request already exists for this booking — it’s on the Review requests list.');
        }

        $msg = $this->notifier->scheduleReviewRequest($booking, force: true);

        return back()->with('status', $msg
            ? 'Review request created — it’s on the Review requests list to send on WhatsApp.'
            : 'Couldn’t create a review request (a return leg may still be running).');
    }

    /**
     * Correct the linked customer record's phone to this booking's calendar
     * "Contact No" — the fix for a booking that got filed under a record holding
     * another booker's number. Messaging already uses the calendar contact; this
     * just tidies the stored record so it stops showing the wrong number.
     */
    public function fixContact(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $calendar = $booking->contactNumberMismatch();
        if (! $calendar || ! $booking->customer) {
            return back()->with('status', 'Nothing to fix — the contact number already matches the calendar.');
        }

        $booking->customer->forceFill(['phone' => $calendar])->save();

        return back()->with('status', "Customer number corrected to the calendar contact ({$calendar}).");
    }

    public function payroll(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'action' => ['required', 'in:set,record,tip,company_collected,confirm_cash'],
            'amount' => ['required_unless:action,company_collected', 'numeric', 'min:0', 'max:100000'],
            'method' => ['required_if:action,tip', 'in:cash,card'],
            'note' => ['nullable', 'string', 'max:200'],
            'collected' => ['nullable', 'boolean'],
        ]);

        // A cash job: confirm (or correct) the cash the driver collects from the
        // customer and move on. Stored as an override so a re-parse of the payment
        // line can't undo the correction. Never owed by the business, so we stop
        // here — nothing to "set" or "record".
        if ($data['action'] === 'confirm_cash') {
            $payroll = $booking->meta['payroll'] ?? ['pay' => null, 'paid' => 0, 'history' => []];
            $payroll['cash_collected'] = round((float) $data['amount'], 2);
            $payroll['cash_confirmed'] = true;
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => $payroll])])->save();

            return $this->afterPayroll($request, $booking)
                ->with('status', 'Cash confirmed — £'.number_format($payroll['cash_collected'], 2).' collected by the driver from the customer.');
        }

        // The 0.01% cash exception: the customer paid the BUSINESS by card, so the
        // business owes the driver. Toggle it and stop here — the office then sets
        // the pay amount as for any card job. Reverting restores cash-settled.
        if ($data['action'] === 'company_collected') {
            $payroll = $booking->meta['payroll'] ?? ['pay' => null, 'paid' => 0, 'history' => []];
            $on = $request->boolean('collected');
            $payroll['company_collected'] = $on;
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => $payroll])])->save();

            $status = $on
                ? 'Marked as paid by card to the business — the business now owes '.$booking->payrollDriverName().' their pay. Set the amount below.'
                : 'Reverted to a cash job settled with the driver.';

            return $this->afterPayroll($request, $booking)->with('status', $status);
        }

        // A gratuity for the driver — kept in the booking_tips ledger, separate
        // from job pay and safe from any meta rewrite.
        if ($data['action'] === 'tip') {
            $amount = round((float) $data['amount'], 2);
            $booking->logTip($amount, $data['method'], note: $data['note'] ?? null, loggedBy: $request->user()->name);

            $where = $data['method'] === 'cash' ? 'cash (driver already has it)' : 'card (owed to the driver)';

            return $this->afterPayroll($request, $booking)
                ->with('status', '£'.number_format($amount, 2)." tip logged for {$booking->payrollDriverName()} — {$where}.");
        }

        $payroll = $booking->meta['payroll'] ?? ['pay' => null, 'paid' => 0, 'history' => []];

        if ($data['action'] === 'set') {
            $payroll['pay'] = round((float) $data['amount'], 2);
            $status = 'Driver pay set to £'.number_format($payroll['pay'], 2).'.';
        } else {
            $amount = round((float) $data['amount'], 2);
            $payroll['paid'] = round(((float) ($payroll['paid'] ?? 0)) + $amount, 2);
            $payroll['history'][] = [
                'amount' => $amount,
                'at' => now()->toDateTimeString(),
                'by' => $request->user()->name,
                'note' => $data['note'] ?? null,
            ];
            $status = '£'.number_format($amount, 2).' recorded as paid to '.$booking->payrollDriverName().'.';
        }

        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => $payroll])])->save();

        return $this->afterPayroll($request, $booking)->with('status', $status);
    }

    /**
     * Where to land after setting pay / recording a payment / logging a tip.
     * When the action came from the Payroll list (from=payroll), go straight back
     * to that list — freshly rendered, so a job that's just had its pay set drops
     * off immediately — instead of leaving the operator on the booking page. From
     * the booking page itself, return to the payroll section, not the top.
     */
    private function afterPayroll(Request $request, Booking $booking): RedirectResponse
    {
        if ($request->input('from') === 'payroll') {
            $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : null;

            return redirect()->to(route('payroll.index', array_filter(['month' => $month])).'#missing-pay');
        }

        return redirect()->to(route('bookings.show', $booking).'#payroll');
    }

    /**
     * Scan this booking against the LIVE Google Calendar: read the event as it
     * stands right now and bring everything on our side — pickup time, title,
     * pickup location, slot and the whole details block — exactly into line.
     * Read-only on Google. Reports precisely what was corrected.
     */
    public function scanCalendar(Request $request, Booking $booking, \App\Services\Calendar\CalendarTimeSync $sync, \App\Services\Calendar\GoogleCalendarService $google): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $result = $sync->scan($booking);

        if ($result['status'] !== 'ok') {
            $ref = $booking->external_reference ?: $booking->reference;
            $diag = $result['diag'] ?? [];

            if (! $google->configured()) {
                $why = 'Google Calendar isn’t connected on the server.';
            } elseif (! $google->active()) {
                $why = 'Calendar sync is paused right now.';
            } elseif (! empty($diag['token_error'])) {
                // The precise reason Google/the server refused a token.
                $why = $diag['token_error'];
            } elseif (empty($diag['read'])) {
                $why = 'Couldn’t read your Google Calendar (a temporary Google error or the calendar isn’t shared with the service account). Try again in a moment.';
            } else {
                // We DID read the calendar but found no matching event.
                $hits = (int) ($diag['ref_hits'] ?? 0) + (int) ($diag['name_hits'] ?? 0);
                $why = $hits > 0
                    ? "Read your calendar and found {$hits} event(s) mentioning “{$ref}” or the customer, but none had “Booking Reference: {$ref}” in the details. Check the reference on the calendar event matches."
                    : "Searched your calendar for “{$ref}” and the customer name but found no matching event. Check the event is on {$this->calendarLabel()} and its reference is in the details.";
            }

            return back()->with('status', '⚠ Scan couldn’t verify against the live calendar: '.$why);
        }

        if ($result['changes'] === []) {
            return back()->with('status', '✅ Scanned the live calendar — this booking matches it exactly. Nothing to correct.');
        }

        return back()
            ->with('status', '🗓 Scanned the live calendar — '.count($result['changes']).' thing(s) corrected to match it.')
            ->with('scanChanges', $result['changes']);
    }

    /** The calendar name for messages (Setting override, else the CET default). */
    private function calendarLabel(): string
    {
        return (string) \App\Models\Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
    }

    /**
     * Match the booking's pickup time to the live calendar event (read-only) —
     * for when the operator corrected the time on the calendar and the system
     * needs to catch up. Never edits the calendar.
     */
    public function syncTime(Request $request, Booking $booking, \App\Services\Calendar\CalendarTimeSync $sync, \App\Services\Calendar\GoogleCalendarService $google): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $result = $sync->pullTime($booking);

        // A clear reason when the live read can't run — the usual cause of
        // "I edited Google but nothing updates" is that the calendar API isn't
        // connected on the server, so we can't read your live edit back.
        $unavailable = ! $google->configured()
            ? 'Google Calendar isn’t connected on the server, so live edits can’t be read. Connect it, or use “Edit booking” to set the time here.'
            : (! $google->active()
                ? 'Calendar sync is paused, so live edits can’t be read right now.'
                : 'Couldn’t read this booking from the live calendar (the event may no longer be linked). Use “Edit booking” to set the time here.');

        return back()->with('status', match ($result['status']) {
            'updated' => "Pickup time updated to {$result['new']} to match the calendar (was {$result['old']}).",
            'matches' => 'The live calendar shows the same time already — nothing to change.',
            default => $unavailable,
        });
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        $user = $request->user();

        return [
            'vehicleTypes' => VehicleType::where('is_active', true)->orderBy('sort_order')->get(),
            'airports' => Airport::where('is_active', true)->orderBy('name')->get(),
            'corporateAccounts' => $user->isAdmin()
                ? CorporateAccount::where('is_active', true)->orderBy('name')->get()
                : $user->corporateAccounts()->where('is_active', true)->get(),
        ];
    }
}
