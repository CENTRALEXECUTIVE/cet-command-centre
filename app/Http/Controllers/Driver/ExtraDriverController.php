<?php

namespace App\Http\Controllers\Driver;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public link for an ADDITIONAL car on a multi-car job (e.g. a 3-car wedding).
 * Each extra driver gets their own token; the page shows the shared job sheet
 * (route, timing, notes, navigation) plus THAT car's own status buttons — so the
 * office tracks each car separately. Extra drivers contact the customer via the
 * office (no masking), and their location isn't tracked — only their status.
 */
class ExtraDriverController extends Controller
{
    /** Statuses an extra driver may set from their link (no cancel/no-show). */
    private const ALLOWED = ['accepted', 'en_route', 'arrived', 'collected', 'complete'];

    public function show(string $token): View
    {
        $booking = Booking::byExtraDriverToken($token);
        $booking?->load(['customer', 'vehicleType', 'airport', 'stops']);
        $car = $booking?->extraDriver($token);

        return view('driver.car', [
            'booking' => $booking,
            'token' => $token,
            'car' => $car,
            'carNumber' => $booking?->extraDriverCarNumber($token),
            'carTotal' => $booking?->carCount(),
        ]);
    }

    public function updateStatus(Request $request, string $token): RedirectResponse
    {
        $booking = Booking::byExtraDriverToken($token);
        $car = $booking?->extraDriver($token);
        abort_if(! $booking || ! $car, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::ALLOWED)],
        ]);

        $from = BookingStatus::from($car['status'] ?? BookingStatus::Allocated->value);
        $to = BookingStatus::from($data['status']);

        // Only allow a legal forward step for this car.
        if ($from !== $to && ! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => 'That step is not allowed for this car.']);
        }

        $booking->setExtraDriverStatus($token, $to->value);

        return back()->with('status', 'Car status updated to '.$to->label().'.');
    }

    /** Extra-car driver confirms they've collected the child seat(s) from the office. */
    public function confirmChildSeats(string $token): RedirectResponse
    {
        $booking = Booking::byExtraDriverToken($token);
        $car = $booking?->extraDriver($token);
        abort_if(! $booking || ! $car, 404);

        $booking->confirmExtraDriverChildSeats($token, null);

        return back()->with('status', 'Thanks — child seat collection confirmed.');
    }
}
