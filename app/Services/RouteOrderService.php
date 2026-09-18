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
     * @return array{scope:string, tabs:Collection, selected:?string, vehicleTabs:Collection, selectedVehicle:string, rows:Collection}
     */
    public function build(?string $route, ?string $scope, ?string $vehicle = null): array
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

        // Some data carries a "FREE_ROAM" airport code — fold it into the real
        // "Free Roam" tag (from isFreeRoam) so there's ONE free-roam bucket.
        $isFreeRoamTag = fn (?string $t) => $t !== null
            && strtoupper(preg_replace('/[^a-z0-9]/i', '', $t)) === 'FREEROAM';
        $normalise = fn (?string $t) => $t === null ? 'Other' : ($isFreeRoamTag($t) ? 'Free Roam' : $t);

        $byTag = $bookings->groupBy(fn (Booking $b) => $normalise($b->journeyFilterTag()));

        $order = Airport::where('is_active', true)->orderBy('code')->pluck('code')
            ->reject($isFreeRoamTag)->values()->all();
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

        // Ordered by when the job CAME THROUGH (was booked/allocated) — most
        // recent first — so the driver column reads as the rotation order given.
        $routeRows = $byTag->get($route, collect())->sortByDesc('created_at')->values();

        // Vehicle-type filter: the Abdi↔Maj rotation only applies to executive
        // jobs, so let the office narrow to a vehicle class. Tabs from the classes
        // present on this route (+ "All"), each with a count.
        $vehName = fn (Booking $b) => $b->vehicleType?->name ?: ($b->displayVehicleType() ?: 'Other');
        $byVehicle = $routeRows->groupBy($vehName);
        $vehicleTabs = collect([['tag' => 'All', 'count' => $routeRows->count()]])
            ->concat($byVehicle->keys()->sort()->map(fn ($name) => [
                'tag' => $name,
                'count' => $byVehicle->get($name)->count(),
            ]))->values();

        $selectedVehicle = $vehicle && $vehicleTabs->contains('tag', $vehicle) ? $vehicle : 'All';
        $rows = $selectedVehicle === 'All'
            ? $routeRows
            : $routeRows->filter(fn (Booking $b) => $vehName($b) === $selectedVehicle)->values();

        // Login drivers that can be reassigned inline from the list.
        $drivers = \App\Models\User::where('is_active', true)->whereHas('driverProfile')
            ->with('driverProfile')->orderBy('name')->get();

        return [
            'scope' => $scope,
            'tabs' => $tabs,
            'selected' => $route,
            'vehicleTabs' => $vehicleTabs,
            'selectedVehicle' => $selectedVehicle,
            'rows' => $rows,
            'drivers' => $drivers,
        ];
    }
}
