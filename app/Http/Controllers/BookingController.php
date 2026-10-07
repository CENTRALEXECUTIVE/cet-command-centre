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
            // Chronological by the field being ranged on: a "came in" (created)
            // view reads first-of-month → last (the order they were booked); a
            // pickup view reads earliest trip → latest.
            $query->orderBy($rangeField);
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
                // Jobs a driver is out on RIGHT NOW (set off → not yet completed),
                // matching the dashboard's "Active now" count — recency-guarded so
                // a job stuck in a driving status days ago can't show. Not tied to
                // today's date, so a late-night job that crossed midnight still shows.
                'active' => $query->whereIn('status', [
                    BookingStatus::EnRoute->value,
                    BookingStatus::Arrived->value,
                    BookingStatus::Collected->value,
                ])->where('pickup_at', '>=', now()->subHours(12))->orderBy('pickup_at'),
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

        // Optionally prefill from an EMAIL enquiry (enquiry=ID) — pulls the whole
        // journey (pickup, drop-off, time, passengers, vehicle, price) the AI
        // already read from the email, so an emailed booking is one click away.
        $enquiry = $request->filled('enquiry')
            ? \App\Models\EmailEnquiry::find($request->integer('enquiry'))
            : null;

        return view('bookings.create', $this->formData($request) + [
            'quote' => $quote,
            'customer' => $customer,
            'enquiry' => $enquiry,
            'prefill' => $enquiry ? $this->prefillFromEnquiry($enquiry) : [],
        ]);
    }

    /** Map an email enquiry's AI-extracted fields onto the booking form. */
    private function prefillFromEnquiry(\App\Models\EmailEnquiry $enquiry): array
    {
        $ex = $enquiry->extracted ?? [];

        $pickupAt = null;
        if (! empty($ex['pickup_datetime'])) {
            try {
                $pickupAt = \Illuminate\Support\Carbon::parse($ex['pickup_datetime'])->format('Y-m-d\TH:i');
            } catch (\Throwable) {
                $pickupAt = null;
            }
        }

        $vehicleId = ! empty($ex['vehicle'])
            ? \App\Models\VehicleType::where('slug', $ex['vehicle'])->value('id')
            : null;

        return array_filter([
            'customer_name' => $enquiry->from_name ?: ($ex['customer_name'] ?? null),
            'customer_email' => $enquiry->from_email,
            'pickup_address' => $ex['pickup'] ?? null,
            'destination_address' => $ex['destination'] ?? null,
            'pickup_at' => $pickupAt,
            'passengers' => $ex['passengers'] ?? null,
            'vehicle_type_id' => $vehicleId,
            'quoted_price' => $enquiry->quote_amount,
            'special_requests' => $ex['notes'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
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

        // Mark the source email enquiry as booked so it drops off the open inbox.
        if ($request->filled('enquiry_id')) {
            \App\Models\EmailEnquiry::where('id', $request->integer('enquiry_id'))
                ->update(['status' => 'booked', 'customer_id' => $booking->customer_id]);
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
        // OFF by default: the office runs the Command Centre as the source of truth,
        // so a booking's displayed data must NEVER be silently rewritten from the
        // Google Calendar on view — that caused "wrong data appears then corrects".
        // The manual "Match calendar" button still pulls from the calendar on demand.
        // Re-enable with the `calendar_autofollow` setting only if the calendar is
        // the source of truth again.
        if (! \App\Models\Setting::get('calendar_autofollow', false)) {
            return;
        }

        // THE OFFICE IS THE BOSS: the moment a booking is edited in the app, its
        // stored copy becomes the truth and the calendar auto-follow NEVER touches
        // it again — no field can be silently pulled back. The office re-syncs on
        // purpose only via the "Match calendar" button.
        if ($booking->manuallyEdited()) {
            return;
        }

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

        // A completed / cancelled booking CAN still be edited — the office needs to
        // correct wrong details (a date, a name) without reverting the status and
        // losing the job's timeline. Editing fields never touches the status history.
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
     * Postpone a booking for a reschedule — the common "the customer's flight was
     * cancelled, they'll rebook once they have new details" case. NOT a
     * cancellation: the record and any payment are HELD against the booking,
     * ready to carry over to the new date. Under the hood it parks the job in the
     * Cancelled lifecycle state (so it drops off every live list, its reminders
     * are cleared and the masked line is closed — the right behaviour for a job
     * that isn't running) but flags meta['postponed'] so the office sees
     * "Postponed — awaiting reschedule", not "Cancelled". The calendar is never
     * touched automatically. Reschedule brings it back (see reschedule()).
     */
    public function postpone(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'postpone_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = ($data['postpone_reason'] ?? null) ?: 'Postponed — awaiting new details';

        try {
            $this->status->transition(
                $booking,
                BookingStatus::Cancelled,
                $request->user(),
                note: 'Postponed for reschedule: '.$reason,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['postpone_reason' => $e->getMessage()]);
        }

        // Free the driver so it leaves their list (it re-allocates on reschedule),
        // and flag the park so it reads as Postponed, not Cancelled. Payment and
        // fare are left exactly as they are — the money is held, not refunded.
        $booking->forceFill([
            'driver_id' => null,
            'meta' => array_merge($booking->meta ?? [], [
                'postponed' => true,
                'postponed_at' => now()->toDateTimeString(),
                'postpone_reason' => $reason,
                'postponed_from_pickup_at' => optional($booking->pickup_at)->toDateTimeString(),
                // This is not a real cancellation — don't leave a cancel reason on it.
                'cancellation_reason' => null,
                'cancelled_at' => null,
            ]),
        ])->save();

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} postponed. The payment is held against it — reschedule it below once the customer has their new details. Remove the Google Calendar event by hand if it was pushed there.");
    }

    /**
     * Reschedule a postponed (or otherwise closed) booking onto a new date/time,
     * carrying the existing payment over — no new charge. Brings the job back into
     * the live flow as Pending so it re-enters allocation. The calendar is never
     * changed automatically; the operator adds the new event by hand.
     */
    public function reschedule(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'pickup_at' => ['required', 'date'],
            'flight_number' => ['nullable', 'string', 'max:32'],
        ]);

        // Pickup times are UK-local wall time (never UTC) — parse in the app tz.
        $newPickup = Carbon::createFromFormat('Y-m-d\TH:i', $data['pickup_at'], config('app.timezone'))
            ?: Carbon::parse($data['pickup_at'], config('app.timezone'));
        $previous = optional($booking->pickup_at)->toDateTimeString();

        $booking->forceFill([
            'pickup_at' => $newPickup,
            'flight_number' => $data['flight_number'] ?? $booking->flight_number,
            'meta' => array_merge($booking->meta ?? [], [
                'postponed' => false,
                'postpone_reason' => null,
                'rescheduled_at' => now()->toDateTimeString(),
                'rescheduled_from' => $previous,
            ]),
        ])->save();

        // Carry the money over untouched and put it back into the live flow.
        if ($booking->status->isTerminal()) {
            $this->status->forceTransition(
                $booking,
                BookingStatus::Pending,
                $request->user(),
                note: 'Rescheduled to '.$newPickup->format('D d M Y, H:i'),
            );
        }

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} rescheduled to {$newPickup->format('D d M, H:i')}. The payment carried over — no new charge. Add it to Google Calendar yourself; the calendar is never changed automatically.");
    }

    /**
     * Clear a booking's ETO-audit flag — the office has checked it and it's fine
     * (or already dealt with). Removes meta['audit_issues'] so it drops off the
     * booking and the Needs-attention list. The office can do anything here.
     */
    public function clearAudit(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $booking->forceFill([
            'meta' => array_merge($booking->meta ?? [], [
                'audit_issues' => [],
                'audit_cleared_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        return redirect()->route('bookings.show', $booking)
            ->with('status', "Audit flag cleared on {$booking->reference}.");
    }

    /**
     * "This isn't a return" — unlink two bookings ETO's a/b suffix wrongly paired
     * as outbound/return (two independent bookings on the same journey). Clears the
     * false "Return" label and the link on both legs. Both bookings stay — only the
     * wrong pairing is removed.
     */
    public function unlinkReturn(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $booking->unlinkReturnPair();

        return redirect()->route('bookings.show', $booking)
            ->with('status', "Unlinked {$booking->reference} — it's no longer treated as a return. Both bookings stay as separate jobs.");
    }

    /**
     * Create the return leg of an existing one-way booking in one tap: a new
     * booking with pickup and drop-off swapped, the same customer / vehicle /
     * passengers / payment, linked to the original both ways. It's left Pending
     * and unpriced for the office to price and allocate — nothing is pushed to the
     * calendar automatically. Does nothing if the booking is already a leg of a pair.
     */
    public function createReturnLeg(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($booking->linked_booking_id || $booking->is_return_leg) {
            return redirect()->route('bookings.show', $booking)
                ->with('status', 'This booking is already part of a return pair.');
        }

        $data = $request->validate([
            'return_pickup_at' => ['required', 'date', 'after:now'],
            'flight_number' => ['nullable', 'string', 'max:32'],
        ]);

        // UK-local wall time — never shifted by a timezone conversion.
        $when = Carbon::createFromFormat('Y-m-d\TH:i', $data['return_pickup_at'], config('app.timezone'))
            ?: Carbon::parse($data['return_pickup_at'], config('app.timezone'));

        $return = \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $when, $data) {
            $leg = $booking->replicate(['reference', 'external_reference', 'status', 'driver_id', 'linked_booking_id', 'quoted_price', 'final_price']);
            $leg->reference = Booking::generateReference();
            // The return leg is operator-created — it must NOT reuse the outbound's
            // external reference (that pair is unique per source_system and would
            // throw a duplicate-key error, and confuse the ETO re-ingest).
            $leg->external_reference = null;
            $leg->status = BookingStatus::Pending;
            $leg->driver_id = null;
            $leg->is_return_leg = true;
            // Swap the ends for the return.
            $leg->pickup_address = $booking->destination_address;
            $leg->pickup_postcode = $booking->destination_postcode;
            $leg->destination_address = $booking->pickup_address;
            $leg->destination_postcode = $booking->pickup_postcode;
            $leg->pickup_at = $when;
            $leg->flight_number = $data['flight_number'] ?? null;
            // The return is priced and paid separately by the office.
            $leg->quoted_price = null;
            $leg->final_price = null;

            // Drop per-leg meta that must not carry across (geocode, audit flags,
            // payroll, any discount/voucher, postpone/reschedule marks, overrides).
            $meta = $booking->meta ?? [];
            foreach (['geo', 'audit_issues', 'payroll', 'discount', 'voucher_code', 'contact_override',
                'rescheduled_from', 'rescheduled_at', 'postponed', 'postpone_reason', 'postponed_at',
                'postponed_from_pickup_at', 'cancellation_reason', 'cancelled_at', 'status_locked_at', 'status_locked_to'] as $k) {
                unset($meta[$k]);
            }
            $leg->meta = $meta ?: null;
            $leg->save();

            // Link both ways so the pair is recognised.
            $leg->forceFill(['linked_booking_id' => $booking->id])->save();
            $booking->forceFill(['linked_booking_id' => $leg->id])->save();

            return $leg;
        });

        return redirect()->route('bookings.show', $return)
            ->with('status', "Return leg {$return->reference} created — pickup and drop-off swapped. Set its price and allocate a driver, then add it to Google Calendar yourself (nothing is pushed automatically).");
    }

    /**
     * Delete a booking (admin). Soft-deletes so it vanishes from every list but is
     * still recoverable, and — per the safety rules — it does NOT touch the Google
     * Calendar event (the operator removes that by hand). A return trip's linked leg
     * is removed too, since it's one journey.
     */
    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $ref = $booking->reference;
        $linked = $booking->linkedBooking; // the other leg of a return, if any

        \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $linked) {
            $linked?->delete();
            $booking->delete();
        });

        return redirect()
            ->route('bookings.index')
            ->with('status', "Booking {$ref}".($linked ? ' (and its return leg)' : '')
                ." deleted. It's removed from the Command Centre and recoverable if needed. "
                .'If it was on Google Calendar, remove that event by hand — the calendar is never touched automatically.');
    }

    /** Bulk action on several bookings from the list (currently: delete). */
    public function bulk(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids' => ['required', 'string'],
        ]);

        $ids = collect(explode(',', $data['ids']))
            ->map(fn ($id) => (int) trim($id))->filter()->unique()->values();

        $count = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($ids, &$count) {
            foreach (Booking::whereIn('id', $ids)->get() as $booking) {
                Booking::withTrashed()->find($booking->linked_booking_id)?->delete();
                $booking->delete();
                $count++;
            }
        });

        return back()->with('status', "{$count} booking(s) deleted — they're in the Trash and recoverable. Google Calendar is untouched.");
    }

    /** The trash: recently deleted bookings an admin can restore or purge. */
    public function trash(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $bookings = Booking::onlyTrashed()
            ->with(['customer', 'vehicleType'])
            ->orderByDesc('deleted_at')
            ->paginate(40);

        return view('bookings.trash', compact('bookings'));
    }

    /** Restore a deleted booking (and its return leg). */
    public function restore(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $linked = Booking::withTrashed()->find($booking->linked_booking_id);
        $booking->restore();
        $linked?->restore();

        return redirect()->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} restored.");
    }

    /** Permanently delete a booking from the trash — cannot be undone. */
    public function forceDestroy(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $ref = $booking->reference;
        $linked = Booking::withTrashed()->find($booking->linked_booking_id);
        \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $linked) {
            $linked?->forceDelete();
            $booking->forceDelete();
        });

        return redirect()->route('bookings.trash')
            ->with('status', "Booking {$ref} permanently deleted. The Google Calendar event, if any, is untouched.");
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
            'passengers' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        $booking->addExtraDriver($data);

        return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', "Added {$data['name']} as another car — copy their link below to send it.");
    }

    /**
     * Set how many passengers ride in one car (shown on that car's link). The
     * lead car uses the token "lead"; extra cars use their own token.
     */
    public function setExtraDriverPassengers(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'passengers' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);
        $n = $data['passengers'] ?? null;

        if ($data['token'] === 'lead') {
            $booking->setLeadCarPassengers($n);

            return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', $n !== null
                ? "Lead car set to carry {$n} passenger(s)."
                : 'Lead car back to the remaining passengers automatically.');
        }

        $car = $booking->extraDriver($data['token']);
        abort_if($car === null, 404);

        $booking->setExtraDriverPassengers($data['token'], $n);

        return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', $n !== null
            ? "Car set to carry {$n} passenger(s)."
            : 'Cleared this car’s passenger count.');
    }

    /**
     * Copy this job's extra cars onto ANOTHER booking the operator picks (usually
     * the return leg). Defaults to the linked leg when no target is chosen.
     */
    public function copyExtraDrivers(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'target_booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
        ]);

        $target = ! empty($data['target_booking_id'])
            ? Booking::find($data['target_booking_id'])
            : $booking->linkedBooking;

        if (! $target) {
            return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', 'Pick which booking to match the cars to.');
        }
        if ($target->is($booking)) {
            return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', "That's this same booking — pick the other leg.");
        }

        $added = $booking->copyExtraDriversTo($target);

        return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', $added > 0
            ? "Matched {$added} car(s) onto {$target->reference} — each has its own link to send."
            : "{$target->reference} already has these cars.");
    }

    /** Remove an extra car from a multi-car job. */
    public function removeExtraDriver(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['token' => ['required', 'string']]);
        $booking->removeExtraDriver($data['token']);

        return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', 'Removed that car from the job.');
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

        return redirect()->to(route("bookings.show", $booking)."#extra-cars")->with('status', $status);
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

    /**
     * Record (or clear) a cancellation charge on a cancelled/no-show booking:
     * the fee kept from the customer and the driver's share. Keeps the job on
     * payroll (driver's cut) and in revenue (the fee) instead of vanishing.
     */
    public function setCancellationCharge(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($request->boolean('clear')) {
            $booking->clearCancellationCharge();

            return back()->with('status', 'Cancellation charge removed — original fare restored.');
        }

        if ($request->boolean('clear_refund')) {
            $booking->clearCancellationRefund();

            return back()->with('status', 'Refund record removed.');
        }

        $data = $request->validate([
            'fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'driver_pay' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'refund' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'refund_reason' => ['nullable', 'string', 'max:300'],
        ]);

        $driverPay = ($data['driver_pay'] ?? null) !== null && $data['driver_pay'] !== ''
            ? (float) $data['driver_pay'] : null;

        // Record the refund FIRST: a full refund sets the charge to £0, which zeroes
        // the fare — and for a job paid without a ledger row the "paid" figure is
        // derived from that fare, so it must be read before the charge is applied.
        $refund = ($data['refund'] ?? null) !== null && $data['refund'] !== ''
            ? (float) $data['refund'] : null;
        if ($refund !== null && $refund > 0.001) {
            $booking->setCancellationRefund($refund, $data['refund_reason'] ?? null, $request->user());
        } else {
            $booking->clearCancellationRefund();
        }

        $booking->setCancellationCharge((float) $data['fee'], $driverPay, $request->user());

        $msg = 'Cancellation saved — £'.number_format((float) $data['fee'], 2).' kept';
        if ($driverPay !== null) {
            $msg .= ', £'.number_format($driverPay, 2).' to '.$booking->payrollDriverName();
        }
        $msg .= '.';
        if ($refund !== null && $refund > 0.001) {
            $msg .= ' Refund of £'.number_format($refund, 2).' recorded — process it in Square by hand.';
        }

        return back()->with('status', $msg);
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
     * Per-pickup contacts for a shared / multi-pickup job. Each via stop that's a
     * separate PARTY carries its own name + number so masking can follow the
     * journey (the current pickup is live, the collected one drops). ADMIN-ONLY:
     * these numbers are never shown to a driver — they only drive the masked line.
     * Stored in meta['stop_contacts'] keyed by via-stop index; a blank number
     * clears that stop's contact (back to a plain waypoint).
     */
    public function pickupContacts(Request $request, Booking $booking, \App\Services\Telephony\TwilioProxyService $proxy): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'contacts' => ['array'],
            'contacts.*.name' => ['nullable', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
        ]);

        $contacts = [];
        foreach ((array) ($data['contacts'] ?? []) as $i => $row) {
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($phone === '') {
                continue; // blank → not a separate party, just a waypoint
            }
            $contacts[(int) $i] = array_filter([
                'name' => trim((string) ($row['name'] ?? '')) ?: null,
                'phone' => $phone,
            ], fn ($v) => $v !== null);
        }

        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'stop_contacts' => $contacts,
        ])])->save();

        // If a mask is open, re-point it at the party who's live now — the number
        // may have just changed. Best-effort; never block the save.
        if ($booking->driver && ! $booking->status->isTerminal()) {
            try {
                $proxy->syncCustomerParticipant($booking->fresh());
            } catch (\Throwable) {
                // masking hiccup must never fail an office edit
            }
        }

        return back()->with('status', 'Pickup contacts saved — each party is reachable on the masked line for their own leg. Numbers stay office-only.');
    }

    /**
     * Save the office "Notes for the driver" — a free-text brief that shows on the
     * driver's job screen for them to read and confirm. Admin-only. Changing the
     * text clears any previous "read" acknowledgement so the driver re-confirms.
     * Numbers should NOT be put here (drivers see it) — reach the customer on the
     * masked line; the panel warns of this.
     */
    /**
     * The customer's preferred notification language — used to pick the wording
     * for confirmation / reminder messages. Stored in meta['notification_language'].
     */
    public function notificationLanguage(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'notification_language' => ['nullable', 'string', 'max:40'],
        ]);

        $meta = $booking->meta ?? [];
        $lang = trim((string) ($data['notification_language'] ?? ''));
        if ($lang === '' || strcasecmp($lang, 'English') === 0) {
            unset($meta['notification_language']);
        } else {
            $meta['notification_language'] = $lang;
        }
        $booking->forceFill(['meta' => $meta])->save();

        return back()->with('status', 'Preferred notification language set to '.$booking->notificationLanguage().'.')
            ->with('scroll', 'booking-details');
    }

    public function driverNotes(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'driver_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $booking->setDriverNotes($data['driver_notes'] ?? null);

        return back()->with('status', blank($data['driver_notes'] ?? null)
            ? 'Notes for the driver cleared.'
            : 'Notes for the driver saved — they’ll see it on their job screen and confirm they’ve read it.');
    }

    /**
     * Per-booking LEAD TIME — the clock time the driver would set their alarm for
     * this job. The "Getting ready" prompt and the emergency escalation key off
     * it, so we never alert before their alarm. Parsed in the app timezone (UK
     * local). Blank clears it, falling back to the smart drive-time estimate.
     */
    public function leadTime(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'lead_time' => ['nullable', 'date'],
        ]);

        if (empty($data['lead_time'])) {
            $booking->setLeadTime(null);

            return back()->with('status', 'Lead time cleared — back to the smart estimate.');
        }

        $at = \Illuminate\Support\Carbon::parse($data['lead_time'], config('app.timezone'));
        $booking->setLeadTime($at);

        return back()->with('status', 'Lead time saved — the driver is alerted from '.$at->format('D d M, H:i').'.');
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
     * Set the job's price (the final fare) straight from the booking page, so a
     * job that was mispriced — e.g. cover cost us more than we charged — can be
     * corrected without opening the full edit form. Writes final_price (the figure
     * fareAmount() uses); blank clears it back to the quote.
     */
    /**
     * Mark (or clear) this booking as a cover job we did for another operator, so
     * we can invoice THEM. Stores their name + contact + the agreed amount; a
     * blank name clears it back to a normal customer-billed job.
     */
    public function setCoverFor(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $booking->setCoverFor(filled($data['name'] ?? null) ? $data : null);

        return back()->with('status', filled($data['name'] ?? null)
            ? 'Cover job saved — you can now invoice '.$data['name'].'.'
            : 'Cover-job details cleared — this is back to a normal customer booking.');
    }

    /** Turn a VAT invoice on/off for this booking — VAT (20%) is then added on top. */
    public function setVatInvoice(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $on = $request->boolean('vat');
        $booking->setVatInvoiceRequested($on);

        $gross = $booking->amountPayable();

        return back()->with('status', $on
            ? 'VAT invoice on — total with 20% VAT is £'.number_format((float) $gross, 2).'. Charge that and send the VAT invoice.'
            : 'VAT invoice off — back to the standard price.');
    }

    public function setPrice(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'final_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $booking->forceFill(['final_price' => $data['final_price'] === '' ? null : $data['final_price']])->save();

        return back()->with('status', $data['final_price'] === null || $data['final_price'] === ''
            ? 'Price cleared — back to the quoted figure.'
            : 'Job price set to £'.number_format((float) $data['final_price'], 2).'.');
    }

    /**
     * Set or clear the billable waiting minutes by hand — the office override,
     * usable even after the job is completed (e.g. a driver forgot to tap POB and
     * the auto figure is wrong). Blank / "clear" removes the override so it falls
     * back to the automatic (capped) figure.
     */
    public function setWaiting(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'waiting_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'clear' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['clear']) || ($data['waiting_minutes'] ?? null) === null) {
            $booking->setWaitingMinutes(! empty($data['clear']) ? 0 : null);

            return back()->with('status', ! empty($data['clear'])
                ? 'Waiting charge removed — set to no billable waiting.'
                : 'Waiting override cleared — back to the automatic figure.');
        }

        $booking->setWaitingMinutes((int) $data['waiting_minutes']);

        return back()->with('status', 'Waiting time set to '.(int) $data['waiting_minutes'].' billable min.');
    }

    /**
     * Toggle whether this booking's shareable driver link is UNBRANDED — for jobs
     * given to outsourced/third-party drivers who shouldn't see the CET branding.
     */
    public function setDriverLinkBranding(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $unbranded = $request->boolean('unbranded');
        $booking->forceFill([
            'meta' => array_merge($booking->meta ?? [], ['driver_link_unbranded' => $unbranded]),
        ])->save();

        return back()->with('status', $unbranded
            ? 'Driver link is now unbranded — safe to send to an outsourced driver.'
            : 'Driver link branding restored.');
    }

    /**
     * Set this booking's contact number (and optionally the lead name) — e.g. when
     * a real number was found in the notes and the driver-visible note was hidden.
     * Stored as a per-booking override so masking, driver-details and reminders all
     * use it, without touching the shared customer record.
     */
    public function setContact(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'contact_number' => ['required', 'string', 'max:32'],
            'lead_name' => ['nullable', 'string', 'max:120'],
        ]);

        $meta = $booking->meta ?? [];
        $meta['contact_override'] = trim($data['contact_number']);
        if (filled($data['lead_name'] ?? null)) {
            $meta['lead_name'] = trim($data['lead_name']);
        }
        $booking->forceFill(['meta' => $meta])->save();

        // Activate this number for masking straight away. The switchboard bridge
        // already reads the contact live on every call; a Twilio Proxy session,
        // though, is tied to a number at open time, so reopen it on the new one.
        try {
            app(\App\Services\Telephony\TwilioProxyService::class)->refreshCustomerContact($booking->fresh());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[masking] refresh after contact change failed: '.$e->getMessage());
        }

        return back()->with('status', 'Contact number updated — masking now connects this number ('
            .$meta['contact_override'].')'.(filled($data['lead_name'] ?? null) ? ' and the name is set.' : '.'));
    }

    /**
     * Ring the assigned driver's phone NOW with a spoken nudge asking them to open
     * the app and update their status. This is the operator's manual version of the
     * watchdog's at-risk call — one call, placed on demand (e.g. the driver's gone
     * quiet and you want them prompted without typing a WhatsApp). Rings the DRIVER
     * only, never the customer.
     */
    public function ringDriver(Request $request, Booking $booking, \App\Services\Telephony\OfficeAlertCall $call): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (! $booking->driver_id || $booking->status->isTerminal()) {
            return back()->with('error', 'No live driver on this job to ring.');
        }
        if (blank($booking->driver?->phone)) {
            return back()->with('error', 'That driver has no phone number saved — add one on their user account first.');
        }
        if (! $call->configured()) {
            return back()->with('error', 'Automated calls aren’t set up yet (Twilio numbers missing). Tap the driver’s number to call them yourself.');
        }

        $where = $booking->pickup_address ? \Illuminate\Support\Str::of($booking->pickup_address)->limit(60) : 'your next job';
        $placed = $call->ringDriver(
            $booking,
            'Please open your driver app and update the status for '.$where.' now.',
        );

        return $placed
            ? back()->with('status', 'Calling '.($booking->driver->name ?? 'the driver').' now — they’ll be asked to update their status.')
            : back()->with('error', 'Couldn’t place the call just now. Try again, or call the driver directly.');
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

        // Candidate bookings to match extra cars onto (the return leg etc.): the
        // same customer's OTHER non-cancelled bookings, newest first, with the
        // linked leg surfaced first when there is one.
        $matchTargets = collect();
        if ($request->user()->isAdmin() && $booking->hasExtraDrivers() && $booking->customer_id) {
            $matchTargets = Booking::where('customer_id', $booking->customer_id)
                ->where('id', '!=', $booking->id)
                ->where('status', '!=', BookingStatus::Cancelled->value)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$booking->linked_booking_id ?? 0])
                ->orderByDesc('pickup_at')
                ->limit(25)
                ->get(['id', 'reference', 'external_reference', 'pickup_at', 'pickup_address', 'destination_address', 'is_return_leg']);
        }

        return compact('booking', 'auditLogs', 'auditLogTotal', 'messages', 'jobDrivers', 'allocatableDrivers', 'canScan', 'backUrl', 'matchTargets');
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
     * Correct the linked customer record's phone to THIS booking's contact number —
     * the fix for a booking that got filed under a record holding another booker's
     * number. Messaging already uses the booking's contact; this just tidies the
     * stored record so it stops showing the wrong number.
     */
    public function fixContact(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $contact = $booking->contactNumberMismatch();
        if (! $contact || ! $booking->customer) {
            return back()->with('status', 'Nothing to fix — the contact number already matches.');
        }

        $customer = $booking->customer;

        // Never overwrite a record that OTHER bookings share — that would change
        // the wrong person's number (e.g. this booking is filed under a different
        // customer). Re-file THIS booking under a record that owns the booking's
        // number instead (matched, or created from the booking's own name), and
        // leave the shared record untouched.
        $sharedByOthers = \App\Models\Booking::where('customer_id', $customer->id)
            ->where('id', '!=', $booking->id)->exists();

        if ($sharedByOthers) {
            $target = \App\Models\Customer::where('phone', $contact)->first()
                ?? \App\Models\Customer::create(array_filter([
                    'name' => $booking->displayName(),
                    'phone' => $contact,
                    'email' => null,
                ], fn ($v) => $v !== null && $v !== ''));
            $booking->forceFill(['customer_id' => $target->id])->save();

            return back()->with('status', "This booking was filed under {$customer->name}, who has other bookings — re-filed it under {$target->name} ({$contact}) and left {$customer->name}'s record untouched.");
        }

        $customer->forceFill(['phone' => $contact])->save();

        return back()->with('status', "Customer record number corrected to the booking's contact ({$contact}).");
    }

    public function payroll(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'action' => ['required', 'in:set,record,tip,company_collected,confirm_cash,mark_paid,set_payee,undo_payment'],
            'amount' => [\Illuminate\Validation\Rule::requiredIf(
                fn () => in_array($request->input('action'), ['set', 'record', 'tip', 'confirm_cash'], true)
            ), 'nullable', 'numeric', 'min:0', 'max:100000'],
            'method' => ['required_if:action,tip', 'in:cash,card'],
            'note' => ['nullable', 'string', 'max:200'],
            'payee' => ['nullable', 'string', 'max:120'],
            'collected' => ['nullable', 'boolean'],
            'index' => ['required_if:action,undo_payment', 'nullable', 'integer', 'min:0'],
        ]);

        // Undo a recorded payment (e.g. "marked paid in full" by mistake / the
        // driver wasn't actually paid). Reverses just that entry's amount from the
        // right bucket and removes it, so the driver is owed it again. Our records
        // only — this never touches the customer's payment.
        if ($data['action'] === 'undo_payment') {
            $payroll = $booking->meta['payroll'] ?? [];
            $history = $payroll['history'] ?? [];
            $idx = (int) $data['index'];
            if (! isset($history[$idx])) {
                return $this->afterPayroll($request, $booking)
                    ->with('status', 'That payment entry was already removed.');
            }
            $entry = $history[$idx];
            $amount = round((float) ($entry['amount'] ?? 0), 2);
            $isTip = ($entry['kind'] ?? null) === 'tip'
                || stripos((string) ($entry['note'] ?? ''), 'tip') !== false;
            if ($isTip) {
                $payroll['tips_paid'] = round(max(0, ((float) ($payroll['tips_paid'] ?? 0)) - $amount), 2);
            } else {
                $payroll['paid'] = round(max(0, ((float) ($payroll['paid'] ?? 0)) - $amount), 2);
            }
            unset($history[$idx]);
            $payroll['history'] = array_values($history);
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => $payroll])])->save();

            return $this->afterPayroll($request, $booking)->with('status',
                '£'.number_format($amount, 2).' payment reverted — '.$booking->payrollDriverName().' is owed it again.');
        }

        // Route this job's driver fee to a PAYEE (e.g. Kash supplied the driver,
        // so we settle with Kash). Blank clears it back to paying the driver.
        if ($data['action'] === 'set_payee') {
            $payee = trim((string) ($data['payee'] ?? ''));
            $meta = $booking->meta ?? [];
            if ($payee === '') {
                unset($meta['pay_to']);
            } else {
                $meta['pay_to'] = $payee;
            }
            $booking->forceFill(['meta' => $meta])->save();

            return $this->afterPayroll($request, $booking)->with('status', $payee === ''
                ? 'Cleared — this job pays the driver directly again.'
                : "This job's pay now goes to {$booking->payTo()}.");
        }

        // One-tap "Mark paid": record whatever is still owed as handed over, so the
        // whole job is settled in a single click from the payroll list or a booking
        // card. No-op (and never negative) when there's nothing left to pay.
        if ($data['action'] === 'mark_paid') {
            $payRemaining = $booking->driverPayRemaining() ?? 0.0;
            $tipRemaining = $booking->cardTipsRemaining();
            $total = round($payRemaining + $tipRemaining, 2);

            // Nothing to settle: no pay set and no card tip owed → nudge to set pay.
            if ($total <= 0.001) {
                return $this->afterPayroll($request, $booking)->with('status',
                    $booking->driverPay() === null
                        ? 'Set this job\'s driver pay first, then mark it paid.'
                        : 'Nothing left to pay on this job.');
            }

            $payroll = $booking->meta['payroll'] ?? ['pay' => null, 'paid' => 0, 'history' => []];
            // Settle the job pay still owed…
            if ($payRemaining > 0.001) {
                $payroll['paid'] = round(((float) ($payroll['paid'] ?? 0)) + $payRemaining, 2);
                $payroll['history'][] = [
                    'amount' => round($payRemaining, 2),
                    'at' => now()->toDateTimeString(),
                    'by' => $request->user()->name,
                    'note' => 'Marked paid in full',
                    'kind' => 'pay',
                ];
            }
            // …and the card tip owed, so it lands in the driver's earnings for the job.
            if ($tipRemaining > 0.001) {
                $payroll['tips_paid'] = round(((float) ($payroll['tips_paid'] ?? 0)) + $tipRemaining, 2);
                $payroll['history'][] = [
                    'amount' => round($tipRemaining, 2),
                    'at' => now()->toDateTimeString(),
                    'by' => $request->user()->name,
                    'note' => 'Card tip paid to driver',
                    'kind' => 'tip',
                ];
            }
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => $payroll])])->save();

            return $this->afterPayroll($request, $booking)->with('status',
                $booking->payrollDriverName().' marked paid in full for this job (£'.number_format($total, 2).').');
        }

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
                'kind' => 'pay',
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
            // Preserve whichever period the payroll page was on — a custom date
            // range (from+to) or a month — so paying doesn't reset the view.
            $rangeFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('range_from')) ? $request->input('range_from') : null;
            $rangeTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('range_to')) ? $request->input('range_to') : null;
            if ($rangeFrom && $rangeTo) {
                return redirect()->to(route('payroll.index', ['from' => $rangeFrom, 'to' => $rangeTo]).'#missing-pay');
            }
            $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : null;

            return redirect()->to(route('payroll.index', array_filter(['month' => $month])).'#missing-pay');
        }

        // From the bookings list: stay on that list (same filter/page/scroll) so
        // the "Driver paid" badge just flips in place.
        if ($request->input('from') === 'bookings') {
            return redirect()->back();
        }

        return redirect()->to(route('bookings.show', $booking).'#payroll');
    }

    /**
     * Scan this booking against the LIVE Google Calendar: read the event as it
     * stands right now and bring everything on our side — pickup time, title,
     * pickup location, slot and the whole details block — exactly into line.
     * Read-only on Google. Reports precisely what was corrected.
     */
    /**
     * COMMAND CENTRE TAKES OVER: push this booking's details FROM the Command
     * Centre TO Google Calendar (the inverse of the old "match calendar"). The
     * app is the source of truth; this rebuilds the event from the booking and
     * sends it to Google, so the calendar mirrors the Command Centre — never the
     * other way round.
     */
    public function pushCalendar(Request $request, Booking $booking, \App\Services\CalendarEventBuilder $builder, \App\Services\Calendar\GoogleCalendarService $google): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $event = $builder->buildFor($booking);

        if ($google->configured() && $google->active() && $google->push($event)) {
            return back()->with('status', 'Pushed this booking to Google Calendar from the Command Centre.');
        }

        return back()->with('status', 'Rebuilt the calendar entry from the Command Centre — it will appear on Google on the next sync.');
    }

    public function scanCalendar(Request $request, Booking $booking, \App\Services\Calendar\CalendarTimeSync $sync, \App\Services\Calendar\GoogleCalendarService $google): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        // Pressing this button is the operator's deliberate "use the calendar as
        // the truth" override: throw away any edit made in the app so the
        // mirrored calendar values (addresses, via stops, details) actually show
        // instead of the edited ones. A normal Save still sticks — and the
        // AUTOMATIC calendar refresh never clears an edit; only this button does.
        $meta = $booking->meta ?? [];
        $discardedEdit = ! empty($meta['edited_fields'])
            || ! empty($meta['manually_edited_at'])
            || $booking->stops()->exists();
        unset($meta['edited_fields'], $meta['manually_edited_at'], $meta['stops'], $meta['stops_reached'], $meta['stop_events'], $meta['eto_via'], $meta['driver_locked']);
        $booking->forceFill(['meta' => $meta])->save();
        $booking->stops()->delete();
        $booking->setRelation('stops', $booking->stops()->getRelated()->newCollection());

        $result = $sync->scan($booking);

        // With the edit flags cleared, the display now mirrors the calendar. Pin
        // the calendar's addresses into the booking's own columns too, so code
        // that reads the raw columns (not just the display accessors) agrees.
        if ($result['status'] === 'ok') {
            $booking->refresh();
            $columns = [];
            if (filled($pick = $booking->calendarField('Pickup Location'))) {
                $columns['pickup_address'] = $pick;
            }
            if (filled($drop = $booking->calendarField('Drop-off Location'))) {
                $columns['destination_address'] = $drop;
            }
            if ($columns !== []) {
                $booking->forceFill($columns)->save();
            }
        }

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

        if ($result['changes'] === [] && ! $discardedEdit) {
            return back()->with('status', '✅ Scanned the live calendar — this booking matches it exactly. Nothing to correct.');
        }

        $note = $discardedEdit
            ? '↩️ Reverted to the calendar — your app edits were discarded and this booking now matches the calendar exactly.'
            : '🗓 Scanned the live calendar — '.count($result['changes']).' thing(s) corrected to match it.';

        return back()
            ->with('status', $note)
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
