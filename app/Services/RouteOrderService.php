<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use Illuminate\Support\Collection;

/**
 * Builds the "route order" view — a route's bookings in time order, each with the
 * driver it's assigned to. Shared by the Driver rotation page and the standalone
 * Route order page so both show the same thing.
 */
class RouteOrderService
{
    /**
     * @return array{scope:string, tabs:Collection, selected:?string, rows:Collection}
     */
    public function build(?string $route, ?string $scope): array
    {
        $scope = in_array($scope, ['today', 'past', 'all'], true) ? $scope : 'upcoming';

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
        $byTag = $bookings->groupBy(fn (Booking $b) => $b->journeyFilterTag() ?? 'Other');

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

        if (! $route || ! $tabs->contains('tag', $route)) {
            $route = $tabs->firstWhere('count', '>', 0)['tag'] ?? ($tabs->first()['tag'] ?? null);
        }

        return [
            'scope' => $scope,
            'tabs' => $tabs,
            'selected' => $route,
            'rows' => $byTag->get($route, collect())->sortBy('pickup_at')->values(),
        ];
    }
}
