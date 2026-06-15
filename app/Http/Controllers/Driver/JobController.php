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

        $jobs = Booking::with(['customer', 'vehicleType', 'airport'])
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
        $booking->load(['customer', 'vehicleType', 'airport', 'stops']);

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

    /** Driver declines an offered job — it returns to the office pool. */
    public function decline(Request $request, Booking $booking): RedirectResponse
    {
        $this->authoriseOwnership($request, $booking);
        $this->status->declineJob($booking, $request->user());

        return redirect()->route('driver.jobs')->with('status', 'Job declined and returned to the office.');
    }

    /** Driver earnings — completed jobs and income for today / this week. */
    public function earnings(Request $request): View
    {
        $driverId = $request->user()->id;
        $revenue = 'COALESCE(final_price, quoted_price, 0)';

        $base = Booking::forDriver($driverId)->where('status', BookingStatus::Complete->value);

        return view('driver.earnings', [
            'today' => [
                'jobs' => (clone $base)->whereDate('pickup_at', today())->count(),
                'earnings' => (float) (clone $base)->whereDate('pickup_at', today())->sum(DB::raw($revenue)),
            ],
            'week' => [
                'jobs' => (clone $base)->whereBetween('pickup_at', [today()->startOfWeek(), today()->endOfWeek()])->count(),
                'earnings' => (float) (clone $base)->whereBetween('pickup_at', [today()->startOfWeek(), today()->endOfWeek()])->sum(DB::raw($revenue)),
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
