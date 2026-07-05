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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BookingStatusService $status,
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

        return view('bookings.create', $this->formData($request) + ['quote' => $quote]);
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
            ->with('status', "Booking {$booking->reference} updated. If it's on the calendar, re-sync to push the change.");
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

        // Customer comms thread (admins only).
        $messages = $request->user()->isAdmin()
            ? $booking->messages()->orderBy('created_at')->get()
            : collect();

        $auditLogs = $request->user()->isAdmin()
            ? $booking->auditLogs()->with('user')->latest('created_at')->get()
            : collect();

        return compact('booking', 'auditLogs', 'messages');
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
