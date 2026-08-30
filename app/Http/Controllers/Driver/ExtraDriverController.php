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
    public function __construct(private readonly \App\Services\Watchdog\AdminAlerts $adminAlerts) {}

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
        $this->pingOffice($booking, $car, $to);

        return back()->with('status', 'Car status updated to '.$to->label().'.');
    }

    /**
     * Notify the office as an extra car moves through the job (set off / arrived /
     * on board / completed) — the same progress pings the lead driver fires, so
     * the office is kept up to date on EVERY vehicle, not just the main one. Uses
     * the same alert types, so each admin's notification toggles apply as usual.
     *
     * @param  array<string, mixed>  $car
     */
    private function pingOffice(Booking $booking, array $car, BookingStatus $to): void
    {
        $carNo = $booking->extraDriverCarNumber($car['token'] ?? '') ?? '?';
        $label = 'Car '.$carNo.' ('.($car['name'] ?? 'Driver').')';
        $time = $booking->pickup_at?->format('H:i') ?? '';

        [$type, $title, $body] = match ($to) {
            BookingStatus::EnRoute => ['driver_set_off', '🚗 '.$label.' has set off', $label.' has set off for the '.$time.' job'],
            BookingStatus::Arrived => ['driver_arrived', '📍 '.$label.' arrived at pickup', $label.' has arrived at the pickup for the '.$time.' job'],
            BookingStatus::Collected => ['driver_on_board', '🧍 '.$label.' — passenger on board', $label.' has the passenger on board'],
            BookingStatus::Complete => ['driver_complete', '🏁 '.$label.' completed', $label.' has completed the '.$time.' job'],
            default => [null, null, null],
        };
        if ($type === null) {
            return;
        }

        \App\Models\WatchdogEvent::log($type, $body, 'info', $booking);
        $this->adminAlerts->notify($type, $title, $body, 'info', $booking);
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

    /** Extra-car driver confirms they've read the office notes. */
    public function confirmNotes(string $token): RedirectResponse
    {
        $booking = Booking::byExtraDriverToken($token);
        $car = $booking?->extraDriver($token);
        abort_if(! $booking || ! $car, 404);

        $booking->confirmExtraDriverNotesRead($token, null);

        return back()->with('status', 'Thanks — noted you’ve read the job notes.');
    }
}
