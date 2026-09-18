<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Route order: pick a route (Free Roam, MAN, BHX, LHR, …) and see that route's
 * bookings in time order, each with the driver it's assigned to — so the office
 * can eyeball that jobs are going out in the right order per route.
 */
class RouteOrderController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $scope = in_array($request->query('scope'), ['today', 'past', 'all'], true)
            ? $request->query('scope')
            : 'upcoming';

        $query = Booking::query()
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->with(['driver', 'customer', 'airport']);

        match ($scope) {
            'today' => $query->whereBetween('pickup_at', [now()->startOfDay(), now()->endOfDay()]),
            'past' => $query->where('pickup_at', '<', now()->startOfDay())->where('pickup_at', '>=', now()->subDays(60)),
            'all' => $query->where('pickup_at', '>=', now()->subDays(60)),
            default => $query->where('pickup_at', '>=', now()->startOfDay()),
        };

        $bookings = $query->orderBy('pickup_at')->limit(2000)->get();

        // Bucket every booking by its route tag (airport code, "Free Roam", else "Other").
        $byTag = $bookings->groupBy(fn (Booking $b) => $b->journeyFilterTag() ?? 'Other');

        // Route tabs: the known airports and Free Roam always offered, plus any
        // other tag that actually has jobs in this scope — each with its count.
        $order = Airport::where('is_active', true)->orderBy('code')->pluck('code')->all();
        $order[] = 'Free Roam';
        foreach ($byTag->keys() as $tag) {
            if (! in_array($tag, $order, true)) {
                $order[] = $tag;
            }
        }
        $tabs = collect($order)->unique()->map(fn ($tag) => [
            'tag' => $tag,
            'count' => $byTag->get($tag, collect())->count(),
        ])->values();

        // Selected route: the one asked for, else the first with any jobs, else the first tab.
        $selected = $request->query('route');
        if (! $selected || ! $tabs->contains('tag', $selected)) {
            $selected = $tabs->firstWhere('count', '>', 0)['tag'] ?? ($tabs->first()['tag'] ?? null);
        }

        $rows = $byTag->get($selected, collect())->sortBy('pickup_at')->values();

        return view('admin.route-order.index', [
            'scope' => $scope,
            'tabs' => $tabs,
            'selected' => $selected,
            'rows' => $rows,
        ]);
    }
}
