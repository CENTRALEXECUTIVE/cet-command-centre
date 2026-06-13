<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Live despatch board (admin). One-tap driver allocation (manual or rotation
 * auto-allocate) and one-tap job status changes across the full lifecycle.
 */
class DespatchController extends Controller
{
    public function __construct(private readonly BookingStatusService $status) {}

    public function index(Request $request): View
    {
        $date = $request->date('date') ?? today();

        $bookings = Booking::with(['customer', 'vehicleType', 'driver', 'airport'])
            ->whereDate('pickup_at', $date)
            ->orderBy('pickup_at')
            ->get();

        // Group by status for the board columns.
        $columns = collect(BookingStatus::cases())
            ->mapWithKeys(fn (BookingStatus $s) => [$s->value => $bookings->where('status', $s)->values()]);

        return view('despatch.board', [
            'date' => $date,
            'columns' => $columns,
            'statuses' => BookingStatus::cases(),
            'drivers' => $this->drivers(),
            'totals' => [
                'all' => $bookings->count(),
                'unallocated' => $bookings->where('status', BookingStatus::Pending)->count(),
                'active' => $bookings->whereIn('status', [BookingStatus::Accepted, BookingStatus::EnRoute, BookingStatus::Collected])->count(),
            ],
        ]);
    }

    public function allocate(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'driver_id' => ['required', Rule::exists('users', 'id')],
        ]);

        $driver = User::findOrFail($data['driver_id']);
        $this->status->allocateDriver($booking, $driver, $request->user());

        return back()->with('status', "{$booking->reference} allocated to {$driver->name}.");
    }

    public function autoAllocate(Request $request, Booking $booking): RedirectResponse
    {
        $booking = $this->status->autoAllocate($booking, $request->user());

        $message = $booking->driver
            ? "{$booking->reference} auto-allocated to {$booking->driver->name}."
            : "{$booking->reference} uses a non-rotation vehicle — allocate a driver manually.";

        return back()->with('status', $message);
    }

    public function updateStatus(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(BookingStatus::values())],
        ]);

        try {
            $this->status->transition($booking, BookingStatus::from($data['status']), $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', "{$booking->reference} → ".BookingStatus::from($data['status'])->label().'.');
    }

    /** @return Collection<int, User> */
    private function drivers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('driverProfile')
            ->orderBy('name')
            ->get();
    }
}
