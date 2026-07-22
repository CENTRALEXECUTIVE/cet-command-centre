<?php

namespace App\Http\Controllers\Driver;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingStatusService;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public shareable DRIVER LINK — a cover driver works their job without a login.
 * The secret token in the URL is the authentication. Shows exactly the driver
 * job page (details, cash to collect, masked number, navigate, status, GPS);
 * updates and pings are scoped to the one booking the token resolves to.
 */
class LinkController extends Controller
{
    public function __construct(private readonly BookingStatusService $status) {}

    public function show(string $token): View
    {
        // Always render the branded page — an unknown/expired token or a finished
        // job shows a tidy message, never a raw 404 or a crash. Only UPDATES are
        // blocked (see resolve()).
        $booking = Booking::byDriverLinkToken($token);
        $booking?->load(['customer', 'vehicleType', 'airport', 'stops', 'calendarEvent', 'driver']);

        return view('driver.link', ['booking' => $booking, 'token' => $token]);
    }

    public function updateStatus(Request $request, string $token): RedirectResponse
    {
        $booking = $this->resolve($token);

        $data = $request->validate([
            'status' => ['required', Rule::in(BookingStatus::values())],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $this->status->linkTransition(
                $booking,
                BookingStatus::from($data['status']),
                $data['lat'] ?? null,
                $data['lng'] ?? null,
            );
        } catch (\InvalidArgumentException|\Illuminate\Auth\Access\AuthorizationException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Status updated to '.BookingStatus::from($data['status'])->label().'.');
    }

    public function location(Request $request, string $token, DriverLocationService $locations): JsonResponse
    {
        $booking = $this->resolve($token);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
        ]);

        $ping = $locations->recordLinkPing($booking, (float) $data['lat'], (float) $data['lng'], $data);

        return response()->json(['recorded' => $ping !== null]);
    }

    /** Resolve the booking or 404. The link dies once the job is terminal. */
    private function resolve(string $token): Booking
    {
        $booking = Booking::byDriverLinkToken($token);
        abort_if($booking === null || $booking->status->isTerminal(), 404);

        return $booking;
    }
}
