<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingStatusService;
use App\Services\Compliance\DriverComplianceService;
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
    public function __construct(
        private readonly BookingStatusService $status,
        private readonly DriverComplianceService $compliance,
    ) {}

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

        $drivers = $this->drivers();
        $blockedDrivers = $drivers
            ->map(fn (User $d) => ['name' => $d->name, 'reason' => $this->compliance->blockReason($d)])
            ->filter(fn ($d) => $d['reason'] !== null)
            ->values();

        return view('despatch.board', [
            'date' => $date,
            'columns' => $columns,
            'statuses' => BookingStatus::cases(),
            'drivers' => $drivers,
            'blockedDrivers' => $blockedDrivers,
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

        // Compliance gate: never dispatch a driver with an expired required
        // document (protects the operator licence).
        if ($reason = $this->compliance->blockReason($driver)) {
            throw ValidationException::withMessages([
                'driver_id' => "Cannot allocate to {$driver->name}: {$reason}. Update the document first.",
            ]);
        }

        $this->status->allocateDriver($booking, $driver, $request->user());

        return back()->with('status', "{$booking->reference} allocated to {$driver->name}.");
    }

    public function autoAllocate(Request $request, Booking $booking): RedirectResponse
    {
        $booking = $this->status->autoAllocate($booking, $request->user());

        // If rotation landed on a non-compliant driver, undo it and flag — don't
        // silently dispatch an expired driver.
        if ($booking->driver && ($reason = $this->compliance->blockReason($booking->driver))) {
            $blocked = $booking->driver->name;
            $booking->forceFill(['driver_id' => null, 'status' => BookingStatus::Pending->value])->save();

            return back()->with('status', "{$booking->reference}: {$blocked} is blocked ({$reason}). Left unallocated — allocate a compliant driver.");
        }

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
            ->with('driverProfile.defaultVehicle')
            ->orderBy('name')
            ->get();
    }
}
