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
    /**
     * Transitions a driver may perform on their own job. Cancelled and No-Show
     * are deliberately EXCLUDED — only an admin (Majid / the office) can mark
     * those.
     */
    private const DRIVER_ALLOWED = ['accepted', 'en_route', 'arrived', 'collected', 'complete'];

    public function __construct(
        private readonly RotationService $rotation,
        private readonly BookingNotifier $notifier,
        private readonly WaitingListService $waitingList,
        private readonly \App\Services\Push\WebPushService $push,
        private readonly \App\Services\Watchdog\AdminAlerts $adminAlerts,
        private readonly \App\Services\Telephony\TwilioProxyService $proxy,
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
     * Driver declines an offered (allocated) job: it returns to the pending pool
     * and the driver is cleared so the office (or rotation) can re-offer it.
     */
    public function declineJob(Booking $booking, User $driver): Booking
    {
        return DB::transaction(function () use ($booking, $driver) {
            $note = "Declined by {$driver->name}";
            $booking->statusHistory()->create([
                'from_status' => $booking->status->value,
                'to_status' => BookingStatus::Pending->value,
                'changed_by' => $driver->id,
                'note' => $note,
                'created_at' => now(),
            ]);
            $booking->forceFill(['status' => BookingStatus::Pending->value, 'driver_id' => null])->save();

            // Declined → the driver loses the masked line immediately.
            $this->proxy->closeSession($booking, 'driver declined');

            return $booking;
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

        return $this->apply($booking, $from, $to, $actor, $lat, $lng, $note);
    }

    /**
     * Admin override — set a booking straight to a status (e.g. Completed, No
     * Show, Cancelled) from the dispatch board without walking every step of the
     * flow. Still authorised (admins only), logged, and fires the same side
     * effects (review request, waiting-list notify) as a normal transition.
     */
    public function forceTransition(Booking $booking, BookingStatus $to, User $actor, ?string $note = null): Booking
    {
        $from = $booking->status;
        if ($from === $to) {
            return $booking;
        }

        $this->authorise($booking, $to, $actor);

        return $this->apply($booking, $from, $to, $actor, null, null, $note);
    }

    /**
     * A driver working the job through the shareable LINK (no login). The token
     * is the authentication; we still enforce a legal, driver-permitted step.
     * The change is stamped to the assigned user if there is one, else recorded
     * with no user and a "via job link" note.
     */
    public function linkTransition(Booking $booking, BookingStatus $to, ?float $lat = null, ?float $lng = null): Booking
    {
        $from = $booking->status;
        if ($from === $to) {
            return $booking;
        }
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException("Cannot move a booking from {$from->value} to {$to->value}.");
        }
        if (! in_array($to->value, self::DRIVER_ALLOWED, true)) {
            throw new AuthorizationException('That status change is not allowed from the driver link.');
        }

        return $this->apply($booking, $from, $to, $booking->driver, $lat, $lng, 'via job link');
    }

    /** Persist the status change, record history and fire side effects. */
    private function apply(
        Booking $booking,
        BookingStatus $from,
        BookingStatus $to,
        ?User $actor,
        ?float $lat,
        ?float $lng,
        ?string $note,
    ): Booking {
        return DB::transaction(function () use ($booking, $from, $to, $actor, $lat, $lng, $note) {
            $booking->forceFill(['status' => $to->value])->save();

            $booking->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $to->value,
                'changed_by' => $actor?->id,
                'gps_latitude' => $lat,
                'gps_longitude' => $lng,
                'note' => $note,
                'created_at' => now(),
            ]);

            $this->fireSideEffects($booking, $to);

            // Control-tower log: every transition is a feed row; no-show and
            // cancel are called out by name so the office spots them.
            $flagged = in_array($to, [BookingStatus::NoShow, BookingStatus::Cancelled], true);
            $who = $booking->driver?->name ?? 'unassigned';
            $title = $booking->pickup_at->format('H:i').' '.$booking->displayName().' → '.$to->label().' · '.$who;
            \App\Models\WatchdogEvent::log($flagged ? $to->value : 'status_changed', $title, 'info', $booking);

            // No-show / cancel also buzz the office phones immediately.
            if ($flagged) {
                $this->adminAlerts->notify('no_show_cancel', $to->label().' — '.$booking->pickup_at->format('H:i'), $title, 'info', $booking);
            }

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
        // "Here's your driver" the moment a driver is allocated. Force-reload the
        // driver/vehicle relations so a stale (rotation-time) driver isn't used.
        if ($to === BookingStatus::Allocated && $booking->driver_id) {
            // Number masking FIRST: open the Twilio Proxy session so the
            // driver-details message below can already carry the masked line.
            if ($booking->driver) {
                $this->proxy->openSession($booking, $booking->driver);
            }

            $this->notifier->sendDriverDetails($booking->load(['customer', 'driver.driverProfile.defaultVehicle', 'vehicle', 'vehicleType']));

            // Buzz the driver's phone with the new job (even if the app is closed).
            // Title carries the date & time; body carries the route + passenger so
            // they know which job it is at a glance (matches the driver-link message).
            if ($booking->driver) {
                $fromPlace = \Illuminate\Support\Str::before((string) $booking->displayPickupAddress(), ',');
                $toPlace = $booking->airport?->code
                    ?: \Illuminate\Support\Str::before((string) $booking->displayDropoffAddress(), ',');
                $route = trim(trim((string) $fromPlace).' → '.trim((string) $toPlace), ' →');
                $name = $booking->meta['lead_name'] ?? $booking->displayName();
                $body = implode(' · ', array_filter([$route, $name]));
                $this->push->sendToUser(
                    $booking->driver,
                    'New job — '.$booking->pickup_at->format('D d M, H:i'),
                    $body,
                    ['url' => route('driver.job', $booking), 'tag' => 'job-'.$booking->id],
                );
            }
        }

        if ($to === BookingStatus::EnRoute) {
            $link = $this->ensureTrackingLink($booking);
            $this->notifier->sendTrackingLink($booking, route('track', $link->token));

            // Tell the office the driver has set off — a positive ping (info, not
            // an alarm). Shows in the alerts feed and pushes to admins who want it.
            $driver = $booking->driverPublicName() ?: 'The driver';
            $where = \Illuminate\Support\Str::before((string) $booking->pickup_address, ',');
            $body = $driver.' has set off for the '.$booking->pickup_at->format('H:i').($where ? ' '.$where : '').' job';
            \App\Models\WatchdogEvent::log('driver_set_off', $body, 'info', $booking);
            $this->adminAlerts->notify('driver_set_off', '🚗 '.$driver.' has set off', $body, 'info', $booking);
        }

        // "Your driver has arrived" — sent when the driver marks Arrived.
        if ($to === BookingStatus::Arrived) {
            $this->notifier->sendArrived($booking);

            $driver = $booking->driverPublicName() ?: 'The driver';
            $body = $driver.' has arrived at the pickup for the '.$booking->pickup_at->format('H:i').' '.($booking->displayCustomerName() ?: '').' job';
            \App\Models\WatchdogEvent::log('driver_arrived', $body, 'info', $booking);
            $this->adminAlerts->notify('driver_arrived', '📍 '.$driver.' arrived at pickup', $body, 'info', $booking);
        }

        // "Passenger on board" — journey underway, reassures the booker.
        if ($to === BookingStatus::Collected) {
            $this->notifier->sendPassengerOnBoard($booking);

            $driver = $booking->driverPublicName() ?: 'The driver';
            $body = $driver.' has the passenger on board'.($booking->displayCustomerName() ? ' — '.$booking->displayCustomerName() : '').', en route to drop-off';
            \App\Models\WatchdogEvent::log('driver_on_board', $body, 'info', $booking);
            $this->adminAlerts->notify('driver_on_board', '🧍 '.$driver.' — passenger on board', $body, 'info', $booking);

            // POB is effectively the end of phone contact — the passenger is in
            // the car. Keep the masked line alive for a short grace window (30
            // min) to cover a bag-left-behind / follow-up call, then let it
            // close. This frees the pooled number for the next job far sooner
            // than holding it to drop-off + grace, and once closed a later call
            // can't reach a driver/customer the number was reassigned to (Twilio
            // rejects out-of-session callers). The terminal close below stays as
            // a backstop for any job that completes without passing through POB.
            $this->proxy->closeAfterMinutes($booking, \App\Services\Telephony\TwilioProxyService::POB_GRACE_MINUTES);
        }

        // Review request 30 minutes after completion (delivered by the scheduler).
        if ($to === BookingStatus::Complete) {
            $this->notifier->scheduleReviewRequest($booking);

            $driver = $booking->driverPublicName() ?: 'The driver';
            $body = $driver.' has completed the '.$booking->pickup_at->format('H:i').' '.($booking->displayCustomerName() ?: '').' job';
            \App\Models\WatchdogEvent::log('driver_complete', $body, 'info', $booking);
            $this->adminAlerts->notify('driver_complete', '🏁 '.$driver.' completed the job', $body, 'info', $booking);
        }

        // A cancellation frees capacity — notify the waiting list.
        if ($to === BookingStatus::Cancelled) {
            $this->waitingList->notifyForCancellation($booking);
        }

        // Cancelled / no-show: there was no journey, so drop any queued reminder
        // or review request — they must never sit in the office worklists.
        if (in_array($to, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            $booking->messages()
                ->where('status', 'queued')
                ->whereIn('type', ['reminder_24h', 'reminder_2h', 'review_request'])
                ->delete();
        }

        // Job closed (complete / cancelled / no-show): wind the public tracking
        // link down shortly after, so the customer sees the final status for a
        // little while and then the link expires. GPS pings already stop on
        // their own — the server rejects pings without an active job.
        if ($to->isTerminal()) {
            $link = $booking->trackingLink()->first();
            $cutoff = now()->addHours(2);
            if ($link && ($link->expires_at === null || $link->expires_at->gt($cutoff))) {
                $link->forceFill(['expires_at' => $cutoff])->save();
            }

            // The masked line dies with the job.
            $this->proxy->closeSession($booking, 'job '.$to->value);
        }
    }

    private function ensureTrackingLink(Booking $booking): TrackingLink
    {
        return $booking->trackingLink()->firstOrCreate([], [
            'token' => Str::random(40),
            'expires_at' => $booking->pickup_at->copy()->addHours(6),
        ]);
    }
}
