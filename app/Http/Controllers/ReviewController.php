<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\Reporting\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * The business Review page — bookings, revenue, vehicle mix, customers, ad spend
 * and an AI analysis (what's working, what to improve, next steps) for a period.
 * The period is chosen by a quick preset (last 30/90 days, this year, last month,
 * all time) or a custom date range.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $review) {}

    public function index(Request $request): View
    {
        [$start, $end, $preset] = $this->resolvePeriod($request);

        return view('review.index', $this->review->build($start, $end) + ['activePreset' => $preset]);
    }

    /**
     * Recover fares onto older bookings that came in without a price, so revenue
     * reads true — the in-app version of `cet:backfill-prices`. Reports back how
     * many were filled and how many still have no price anywhere in the system.
     */
    public function backfillPrices(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        Artisan::call('cet:backfill-prices');
        $out = trim(preg_replace('/\s+/', ' ', Artisan::output())) ?: 'Done.';

        return redirect()->route('review.index', $request->only('preset', 'start', 'end'))
            ->with('status', $out);
    }

    /**
     * Resolve the reporting window from a ?preset= or explicit ?start=/?end= dates.
     * A custom date range (no preset) wins; otherwise the named preset; default is
     * the last 30 days.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string|null}
     */
    private function resolvePeriod(Request $request): array
    {
        $now = now();
        $preset = $request->query('preset');

        // Explicit custom range (and no preset) → honour the pickers exactly.
        if (! $preset && ($request->date('start') || $request->date('end'))) {
            return [
                ($request->date('start') ?? $now->copy()->subDays(29))->startOfDay(),
                ($request->date('end') ?? $now)->endOfDay(),
                'custom',
            ];
        }

        [$start, $end] = match ($preset) {
            'last90' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            // Runs to year-end so upcoming (reserved) jobs are included; the
            // earned figure still only counts trips that have actually run.
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'all' => [$this->earliestBooking($now), $now->copy()->endOfYear()],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        return [$start, $end, $preset ?: 'last30'];
    }

    /** Earliest booking date (for "all time"), falling back to 3 years ago. */
    private function earliestBooking(Carbon $now): Carbon
    {
        $earliest = Booking::min('pickup_at');

        return ($earliest ? Carbon::parse($earliest) : $now->copy()->subYears(3))->startOfDay();
    }
}
