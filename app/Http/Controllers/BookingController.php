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
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Booking::with(['customer', 'vehicleType', 'driver', 'corporateAccount'])
            ->orderByDesc('pickup_at');

        // Corporate clients only ever see their own account's bookings.
        if ($user->isCorporateClient()) {
            $query->whereIn('corporate_account_id', $user->corporateAccounts->pluck('id'));
        }

        return view('bookings.index', [
            'bookings' => $query->paginate(20),
        ]);
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

        return view('bookings.show', $this->showData($request, $booking));
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
     * Match the booking's pickup time to the live calendar event (read-only) —
     * for when the operator corrected the time on the calendar and the system
     * needs to catch up. Never edits the calendar.
     */
    public function syncTime(Request $request, Booking $booking, \App\Services\Calendar\CalendarTimeSync $sync): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $result = $sync->pullTime($booking);

        return back()->with('status', match ($result['status']) {
            'updated' => "Pickup time updated to {$result['new']} to match the calendar (was {$result['old']}).",
            'matches' => 'Pickup time already matches the calendar — nothing changed.',
            default => 'Could not read this booking from the calendar (it may not be connected or the event isn’t linked).',
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
