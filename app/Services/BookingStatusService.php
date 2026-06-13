<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The despatch status engine. Enforces the legal job lifecycle, writes the full
 * audit trail (every transition with actor and GPS position), and fires the
 * side effects each status implies — e.g. generating the tracking link and
 * sending it to the customer the moment the driver goes En Route.
 */
class BookingStatusService
{
    /** Transitions a driver may perform on their own job. */
    private const DRIVER_ALLOWED = ['accepted', 'en_route', 'collected', 'complete', 'no_show'];

    public function __construct(
        private readonly RotationService $rotation,
        private readonly BookingNotifier $notifier,
    ) {}

    public function canTransition(BookingStatus $from, BookingStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Allocate a driver to a booking with one tap (manual override). Moves the
     * booking to "allocated". Does NOT advance the rotation pointer — manual
     * allocation is a deliberate override of the rotation.
     */
    public function allocateDriver(Booking $booking, User $driver, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $driver, $actor) {
            $booking->forceFill(['driver_id' => $driver->id])->save();

            return $this->transition($booking, BookingStatus::Allocated, $actor, note: "Allocated to {$driver->name}");
        });
    }

    /**
     * Auto-allocate using the rotation engine (Executive saloon jobs). Returns
     * the booking; driver is null when the vehicle type does not affect rotation.
     */
    public function autoAllocate(Booking $booking, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $actor) {
            $driver = $this->rotation->allocate($booking);

            if ($driver) {
                $this->transition($booking->refresh(), BookingStatus::Allocated, $actor, note: "Auto-allocated to {$driver->name} (rotation)");
            }

            return $booking->refresh();
        });
    }

    /**
     * Perform a status transition, recording history (+ GPS) and side effects.
     *
     * @throws InvalidArgumentException when the transition is not permitted
     * @throws AuthorizationException when the actor may not perform it
     */
    public function transition(
        Booking $booking,
        BookingStatus $to,
        User $actor,
        ?float $lat = null,
        ?float $lng = null,
        ?string $note = null,
    ): Booking {
        $from = $booking->status;

        if ($from === $to) {
            return $booking;
        }

        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException("Cannot move a booking from {$from->value} to {$to->value}.");
        }

        $this->authorise($booking, $to, $actor);

        return DB::transaction(function () use ($booking, $from, $to, $actor, $lat, $lng, $note) {
            $booking->forceFill(['status' => $to->value])->save();

            $booking->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $to->value,
                'changed_by' => $actor->id,
                'gps_latitude' => $lat,
                'gps_longitude' => $lng,
                'note' => $note,
                'created_at' => now(),
            ]);

            $this->fireSideEffects($booking, $to);

            return $booking;
        });
    }

    private function authorise(Booking $booking, BookingStatus $to, User $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->isDriver()) {
            $ownsJob = $booking->driver_id === $actor->id;
            $allowed = in_array($to->value, self::DRIVER_ALLOWED, true);

            if ($ownsJob && $allowed) {
                return;
            }
        }

        throw new AuthorizationException('You are not permitted to make this status change.');
    }

    private function fireSideEffects(Booking $booking, BookingStatus $to): void
    {
        if ($to === BookingStatus::EnRoute) {
            $link = $this->ensureTrackingLink($booking);
            $this->notifier->sendTrackingLink($booking, route('track', $link->token));
        }

        // Hook points for later phases:
        // - Complete → schedule the 30-minute review request (Phase 4)
        // - Cancelled → trigger waiting-list fill (Phase 5)
    }

    private function ensureTrackingLink(Booking $booking): TrackingLink
    {
        return $booking->trackingLink()->firstOrCreate([], [
            'token' => Str::random(40),
            'expires_at' => $booking->pickup_at->copy()->addHours(6),
        ]);
    }
}
