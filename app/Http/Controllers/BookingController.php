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

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Booking::with(['customer', 'vehicleType', 'driver', 'corporateAccount', 'calendarEvent'])
            ->orderByDesc('pickup_at');

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

        $bookings = $query->paginate(20)->withQueryString();

        return view('bookings.index', ['bookings' => $bookings, 'q' => $q]);
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

    public function show(Request $request, Booking $booking): View
    {
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
     * If the live Google Calendar is reachable, pull this single booking's time
     * from its live event so the page reflects any edit made directly on Google.
     * Silent and best-effort: skipped entirely when the calendar isn't connected,
     * and any read error leaves our stored copy untouched.
     */
    private function autoFollowCalendar(Booking $booking): void
    {
        if (! $this->google->configured() || ! $this->google->active()) {
            return; // not connected — the manual "Match time to calendar" button still works
        }
        if (blank($booking->calendarEvent?->google_event_id)) {
            return;
        }

        try {
            $this->timeSync->pullTime($booking);
        } catch (\Throwable $e) {
            // Never let a calendar read break the booking page.
            \Illuminate\Support\Facades\Log::warning('Auto-follow calendar read failed', [
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

    /** @return array<string, mixed> */
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

        $auditLogs = $request->user()->isAdmin()
            ? $booking->auditLogs()->with('user')->latest('created_at')->get()
            : collect();

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

        return compact('booking', 'auditLogs', 'messages', 'jobDrivers');
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
    public function payroll(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'action' => ['required', 'in:set,record'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

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

        return back()->with('status', $status);
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
            $why = ! $google->configured()
                ? 'Google Calendar isn’t connected on the server, so the live calendar can’t be read.'
                : (! $google->active()
                    ? 'Calendar sync is paused, so the live calendar can’t be read right now.'
                    : 'This booking isn’t linked to a live calendar event (no event id), so there’s nothing to scan against.');

            return back()->with('status', '⚠ Scan not possible: '.$why);
        }

        if ($result['changes'] === []) {
            return back()->with('status', '✅ Scanned the live calendar — this booking matches it exactly. Nothing to correct.');
        }

        return back()
            ->with('status', '🗓 Scanned the live calendar — '.count($result['changes']).' thing(s) corrected to match it.')
            ->with('scanChanges', $result['changes']);
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
