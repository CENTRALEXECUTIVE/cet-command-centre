<?php

namespace App\Http\Controllers\Driver;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Driver mobile app (mobile-browser, no app store). Today / tomorrow / this
 * week filters and one-tap status updates that capture the driver's GPS
 * position at the moment of each change.
 */
class JobController extends Controller
{
    public function __construct(private readonly BookingStatusService $status) {}

    public function index(Request $request): View
    {
        $filter = $request->query('filter', 'today');
        $driverId = $request->user()->id;

        [$from, $to, $label] = match ($filter) {
            'tomorrow' => [today()->addDay()->startOfDay(), today()->addDay()->endOfDay(), 'Tomorrow'],
            'week' => [today()->startOfDay(), today()->endOfWeek(), 'This Week'],
            default => [today()->startOfDay(), today()->endOfDay(), 'Today'],
        };

        $jobs = Booking::with(['customer', 'vehicleType', 'airport', 'stops'])
            ->forDriver($driverId)
            ->whereBetween('pickup_at', [$from, $to])
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->orderBy('pickup_at')
            ->get();

        return view('driver.jobs', compact('jobs', 'filter', 'label'));
    }

    public function show(Request $request, Booking $booking): View
    {
        $this->authoriseOwnership($request, $booking);
        $booking->load(['customer', 'vehicleType', 'airport', 'stops', 'calendarEvent']);

        // Keep the job screen in step with the LIVE calendar (read-only).
        app(\App\Services\Calendar\LiveCalendarRefresh::class)->refresh($booking);

        return view('driver.job', compact('booking'));
    }

    public function updateStatus(Request $request, Booking $booking): RedirectResponse
    {
        $this->authoriseOwnership($request, $booking);

        $data = $request->validate([
            'status' => ['required', Rule::in(BookingStatus::values())],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $this->status->transition(
                $booking,
                BookingStatus::from($data['status']),
                $request->user(),
                lat: $data['lat'] ?? null,
                lng: $data['lng'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Status updated to '.BookingStatus::from($data['status'])->label().'.');
    }

    /**
     * Multi-stop journeys: the driver taps "reached" at each via stop while the
     * passenger is on board, so the screen guides them to the next stop before
     * the final drop-off. Only advances while there's a stop still to reach.
     */
    public function reachStop(Request $request, Booking $booking): RedirectResponse
    {
        $this->authoriseOwnership($request, $booking);

        if ($booking->status === BookingStatus::Collected && ! $booking->allViaStopsReached()) {
            $booking->markStopReached();
        }

        return back()->with('status', $booking->allViaStopsReached()
            ? 'Reached the last stop — you can complete the job at drop-off.'
            : 'Stop reached — head to the next one.');
    }

    /** Driver OKs the "collect the cash" reminder on a cash job. */
    public function acknowledgeCash(Request $request, Booking $booking): RedirectResponse
    {
        $this->authoriseOwnership($request, $booking);
        $booking->acknowledgeCashCollect($request->user());

        return back()->with('status', 'Thanks — noted you’ll collect the cash.');
    }

    /**
     * Answer an office "request location": record a one-off position for this
     * job (works even before Set off) so the office can see where the driver is.
     */
    public function shareLocation(Request $request, Booking $booking, \App\Services\DriverLocationService $locations): \Illuminate\Http\JsonResponse
    {
        $this->authoriseOwnership($request, $booking);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
        ]);

        $ping = $locations->recordRequestedPing($request->user(), $booking, (float) $data['lat'], (float) $data['lng'], $data);

        return response()->json(['shared' => $ping !== null]);
    }

    /** Driver declines an offered job — it returns to the office pool. */
    public function decline(Request $request, Booking $booking): RedirectResponse
    {
        $this->authoriseOwnership($request, $booking);
        $this->status->declineJob($booking, $request->user());

        return redirect()->route('driver.jobs')->with('status', 'Job declined and returned to the office.');
    }

    /**
     * Driver earnings — completed jobs and THEIR PAY (from the payroll set per
     * job), never the customer fare. Drivers must not see booking prices.
     */
    public function earnings(Request $request): View
    {
        $driverId = $request->user()->id;

        $base = Booking::forDriver($driverId)->where('status', BookingStatus::Complete->value);
        $pay = fn ($q) => (float) $q->get()->sum(fn (Booking $b) => $b->driverPay() ?? 0);

        return view('driver.earnings', [
            'today' => [
                'jobs' => (clone $base)->whereDate('pickup_at', today())->count(),
                'earnings' => $pay((clone $base)->whereDate('pickup_at', today())),
            ],
            'week' => [
                'jobs' => (clone $base)->whereBetween('pickup_at', [today()->startOfWeek(), today()->endOfWeek()])->count(),
                'earnings' => $pay((clone $base)->whereBetween('pickup_at', [today()->startOfWeek(), today()->endOfWeek()])),
            ],
            'recent' => (clone $base)->with('vehicleType')->orderByDesc('pickup_at')->limit(20)->get(),
        ]);
    }

    /** JSON: count of offered (allocated, unaccepted) jobs — for live polling. */
    public function offersCount(Request $request)
    {
        return response()->json([
            'offers' => Booking::forDriver($request->user()->id)
                ->where('status', BookingStatus::Allocated->value)->count(),
        ]);
    }

    private function authoriseOwnership(Request $request, Booking $booking): void
    {
        abort_unless($booking->driver_id === $request->user()->id, 403);
    }
}
