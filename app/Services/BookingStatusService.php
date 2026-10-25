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
     * A public web booking has just been PAID in full (Square webhook). Promote it
     * from a pending request to a confirmed job: stamp the VAT breakdown for the
     * receipt, allocate a rotation driver where the vehicle type uses rotation,
     * build the calendar event (the sync job pushes it to Google when live), and
     * tell the office. Idempotent — safe to call again on a repeated webhook, and
     * a no-op for anything that isn't a still-pending paid web booking.
     */
    public function confirmPaidWebBooking(Booking $booking, ?User $actor = null): Booking
    {
        if ($booking->source !== 'web'
            || ($booking->payment_status ?? null) !== 'paid'
            || $booking->status !== BookingStatus::Pending) {
            return $booking;
        }

        return DB::transaction(function () use ($booking, $actor) {
            // Freeze the VAT split at the moment of payment for the receipt.
            if ($vat = $booking->fareVatBreakdown()) {
                $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
                    'vat' => $vat,
                    'confirmed_paid_at' => now()->toIso8601String(),
                ])])->save();
            }

            // Consume a voucher only now the booking is actually paid (so an
            // abandoned checkout never burns a one-use code). Idempotent.
            $voucherMeta = $booking->meta['voucher'] ?? null;
            if (is_array($voucherMeta) && ! empty($voucherMeta['id']) && empty($voucherMeta['redeemed'])) {
                if ($voucher = \App\Models\Voucher::find($voucherMeta['id'])) {
                    $voucher->redeem();
                }
                $meta = $booking->meta ?? [];
                $meta['voucher']['redeemed'] = now()->toIso8601String();
                $booking->forceFill(['meta' => $meta])->save();
            }

            // Rotation for executive-saloon-style jobs; null for others (left for
            // the office to allocate by hand).
            $driver = $this->rotation->allocate($booking);
            if ($driver) {
                $booking->forceFill(['driver_id' => $driver->id])->save();
                $booking->statusHistory()->create([
                    'from_status' => BookingStatus::Pending->value,
                    'to_status' => BookingStatus::Allocated->value,
                    'changed_by' => $actor?->id,
                    'note' => "Auto-allocated to {$driver->name} (rotation) — web booking paid",
                    'created_at' => now(),
                ]);
                $booking->forceFill(['status' => BookingStatus::Allocated->value])->save();
            }

            // Build our calendar event; pushed to Google by cet:sync-calendar when
            // the integration is live. Never fatal to the confirmation.
            try {
                app(\App\Services\CalendarEventBuilder::class)
                    ->buildFor($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));
            } catch (\Throwable) {
            }

            $fresh = $booking->fresh();

            // Email the customer the PAID confirmation + invoice (once).
            $this->emailPaidConfirmation($fresh);
            $fresh = $fresh->fresh();

            $name = $fresh->customer?->name ?? 'Web customer';
            \App\Models\WatchdogEvent::log('web_booking_paid', 'Web booking PAID — '.$name, 'info', $fresh);
            $this->adminAlerts->notify('web_booking',
                '💳 Web booking PAID — '.$name,
                $name.' paid £'.number_format((float) ($fresh->fareGross() ?? 0), 2).' online for '
                    .$fresh->pickup_at?->format('D d M, H:i').'.'
                    .($fresh->driver ? ' Auto-allocated to '.$fresh->driver->name.'.' : ' Needs a driver.'),
                'info', $fresh);

            return $fresh;
        });
    }

    /**
     * Send the customer the PAID booking confirmation (with the invoice attached)
     * once their payment lands — for ANY booking (web or office-made), with a
     * customer email, exactly once. Uses its own `paid_email_sent` flag so it
     * fires even when a pending confirmation was already emailed at booking time.
     * A send failure is reported to the office, never fatal.
     */
    public function emailPaidConfirmation(Booking $booking): void
    {
        if (! empty($booking->meta['paid_email_sent'])) {
            return;
        }
        // Safety gate: never auto-email about an old, finished, cancelled or
        // address-less booking.
        $reason = '';
        if (! $booking->customerEmailAllowed($reason)) {
            \Illuminate\Support\Facades\Log::info('[email] paid confirmation skipped ('.$reason.') for '.$booking->reference);

            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::to($booking->customer->email)
                ->send(new \App\Mail\BookingConfirmationMail($booking, paid: true));
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
                'paid_email_sent' => now()->toIso8601String(),
            ])])->save();
        } catch (\Throwable $e) {
            \App\Support\MailFailure::report('paid booking confirmation', $e, $booking);
        }
    }

    /**
     * Notify the OFFICE (ops inbox) about a booking at a given stage — 'new'
     * when a booking is created, 'paid' when its fare is paid online. Sent at
     * most once per stage per booking (stamped in meta) so retries/webhooks
     * don't double-send. No-op when there's no ops inbox configured.
     */
    public function emailOffice(Booking $booking, string $stage = 'new'): void
    {
        $ops = config('cet.ops_email');
        $flag = 'office_email_'.$stage.'_sent';
        if (blank($ops) || ! empty($booking->meta[$flag])) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::to($ops)
                ->send(new \App\Mail\OfficeBookingMail($booking->fresh(), $stage));
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
                $flag => now()->toIso8601String(),
            ])])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[office] booking email ('.$stage.') failed: '.$e->getMessage());
        }
    }

    /**
     * A fare has just been paid — the single entry point however we learn of it
     * (a provider webhook, or the office opening a booking the webhook was missed
     * for). Idempotent: every step is guarded, so it's safe to call repeatedly.
     *
     *  1. Heal the status column to paid (a recorded payment is authoritative).
     *  2. Web bookings: allocate + build the calendar + alert the office.
     *  3. Email the CUSTOMER the paid confirmation + invoice (once).
     *  4. Email the OFFICE the "payment received" confirmation (once) — so admins
     *     always get the second email when a customer pays.
     */
    public function onFarePaid(Booking $booking): Booking
    {
        $booking->reconcilePaidStatus();
        $fresh = $booking->fresh();

        // Web bookings: allocation + calendar + web-paid alert (internally guarded
        // to source=web, paid, still pending).
        $this->confirmPaidWebBooking($fresh);
        $fresh = $fresh->fresh();

        // The two confirmation emails, each sent at most once.
        $this->emailPaidConfirmation($fresh);
        $this->emailOffice($fresh->fresh(), 'paid');

        // A return CAN be paid in one go (the combined pay link charges the whole
        // trip) — but a leg is also often paid on its OWN. Only settle the other
        // legs when THIS payment actually covered the whole group total; never mark a
        // sibling paid off a single-leg payment (that falsely showed the unpaid
        // return as paid and emailed the customer). The amount recorded on this leg
        // is the authority.
        $group = $fresh->invoiceGroupBookings();
        if ($group->count() > 1) {
            $thisPaid = (float) ($fresh->meta['square_payment']['amount'] ?? 0);
            if ($thisPaid <= 0) {
                $thisPaid = (float) $fresh->transactionsPaidTotal();
            }
            $groupTotal = round($group->sum(fn (Booking $b) => (float) ($b->fareGross() ?? 0)), 2);
            // Only cascade when the payment genuinely covers the whole trip.
            if ($groupTotal > 0 && $thisPaid + 0.01 >= $groupTotal) {
                $basePaymentId = $fresh->meta['square_payment']['id'] ?? ('GRP-'.$fresh->id);
                $entity = $fresh->meta['square_payment']['entity'] ?? null;
                foreach ($group as $leg) {
                    if ($leg->is($fresh) || $leg->fareIsPaid()) {
                        continue;
                    }
                    $leg->markFarePaid($basePaymentId.'-L'.$leg->id, (float) ($leg->fareGross() ?? 0), $entity);
                    $leg->fresh()->reconcilePaidStatus();
                    // Mark it paid + confirm the web booking, but DON'T send a second
                    // customer email — this one payment already got one confirmation.
                    $this->confirmPaidWebBooking($leg->fresh());
                }
            }
        }

        return $fresh->fresh();
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
     * A DRIVER closing their OWN job from the drop-off. Skips the sequential
     * requirement (normally Complete only follows "Passenger on board"), so a
     * driver who's actually at the destination can ALWAYS finish — even if an
     * intermediate tap was missed on a long job. The caller (driver job screen /
     * link) still enforces the only rule that matters: within ~1 mile of the
     * drop-off with location on. Records the completing GPS fix, and only fires
     * from a live, accepted state so it can't resurrect a pending/allocated job.
     */
    public function completeByDriver(Booking $booking, ?User $driver, ?float $lat = null, ?float $lng = null): Booking
    {
        $from = $booking->status;
        if ($from === BookingStatus::Complete) {
            return $booking;
        }
        // Normal sequential path still applies when it legally can (keeps POB side
        // effects etc.); only short-circuit when the driver skipped a step.
        if ($this->canTransition($from, BookingStatus::Complete)) {
            return $this->apply($booking, $from, BookingStatus::Complete, $driver, $lat, $lng, null);
        }
        if (! in_array($from, [BookingStatus::Accepted, BookingStatus::EnRoute, BookingStatus::Arrived, BookingStatus::Collected], true)) {
            throw new InvalidArgumentException("Can't complete a job from {$from->value}.");
        }
        if ($driver) {
            $this->authorise($booking, BookingStatus::Complete, $driver);
        }

        return $this->apply($booking, $from, BookingStatus::Complete, $driver, $lat, $lng, 'completed at drop-off');
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
            // Stamp that a PERSON set this status in the app. This is the office's
            // (or driver's) decision and it must STICK — the ETO email ingestion
            // won't silently revert it (e.g. re-cancel a booking the office put
            // back to allocated). Only in-app changes come through here; the email
            // importer writes status directly and never sets this lock.
            $booking->forceFill([
                'status' => $to->value,
                'meta' => array_merge($booking->meta ?? [], [
                    'status_locked_at' => now()->toIso8601String(),
                    'status_locked_to' => $to->value,
                ]),
            ])->save();

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
        // Progress pings to the office (set off / arrived / POB / complete) are
        // only useful for LIVE jobs — today's and future ones. A past job (pickup
        // before today) being marked up retroactively by an admin isn't live, so
        // it must NOT buzz the office. Present + future only.
        $liveJob = $booking->pickup_at && $booking->pickup_at->gte(now()->startOfDay());

        // "Here's your driver" the moment a driver is allocated. Force-reload the
        // driver/vehicle relations so a stale (rotation-time) driver isn't used.
        if ($to === BookingStatus::Allocated && $booking->driver_id) {
            // Auto-fill the driver's pay (90% of the fare) the moment a job is
            // allocated, so "driver owed" is already correct in payroll without the
            // office confirming each one. Won't overwrite a price already offered.
            $booking->applyDefaultDriverPay();

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
            // Live jobs only — a past job marked up later doesn't buzz the office.
            if ($liveJob) {
                $driver = $booking->driverPublicName() ?: 'The driver';
                $where = \Illuminate\Support\Str::before((string) $booking->pickup_address, ',');
                $body = $driver.' has set off for the '.$booking->pickup_at->format('H:i').($where ? ' '.$where : '').' job';
                \App\Models\WatchdogEvent::log('driver_set_off', $body, 'info', $booking);
                $this->adminAlerts->notify('driver_set_off', '🚗 '.$driver.' has set off', $body, 'info', $booking);
            }
        }

        // "Your driver has arrived" — sent when the driver marks Arrived.
        if ($to === BookingStatus::Arrived) {
            $this->notifier->sendArrived($booking);

            if ($liveJob) {
                $driver = $booking->driverPublicName() ?: 'The driver';
                $body = $driver.' has arrived at the pickup for the '.$booking->pickup_at->format('H:i').' '.($booking->displayCustomerName() ?: '').' job';
                \App\Models\WatchdogEvent::log('driver_arrived', $body, 'info', $booking);
                $this->adminAlerts->notify('driver_arrived', '📍 '.$driver.' arrived at pickup', $body, 'info', $booking);
            }
        }

        // "Passenger on board" — journey underway, reassures the booker.
        if ($to === BookingStatus::Collected) {
            // Freeze the waiting time (billable minutes past the grace period)
            // now the passenger is in the car and the wait has ended.
            $booking->recordWaitingTime();

            $this->notifier->sendPassengerOnBoard($booking);

            if ($liveJob) {
                $driver = $booking->driverPublicName() ?: 'The driver';
                $body = $driver.' has the passenger on board'.($booking->displayCustomerName() ? ' — '.$booking->displayCustomerName() : '').', en route to drop-off';
                \App\Models\WatchdogEvent::log('driver_on_board', $body, 'info', $booking);
                $this->adminAlerts->notify('driver_on_board', '🧍 '.$driver.' — passenger on board', $body, 'info', $booking);
            }

            // Shared/multi-pickup job: the FIRST passenger is aboard but there are
            // more pickups to collect — move the masked line on to the next party
            // (their number goes live, the collected passenger's drops) instead of
            // winding it down. The switchboard follows the current party on its
            // own; this re-points a Twilio Proxy session.
            if ($booking->hasMultiplePickupParties() && ! $booking->pickupsAllCollected()) {
                $this->proxy->syncCustomerParticipant($booking);
            } else {
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
        }

        // Review request 30 minutes after completion (delivered by the scheduler).
        if ($to === BookingStatus::Complete) {
            $this->notifier->scheduleReviewRequest($booking);
            // Tip link, created ready-to-send the moment the job completes (sent by
            // hand from the booking page, like every other customer message).
            $this->notifier->scheduleTipRequest($booking);

            if ($liveJob) {
                $driver = $booking->driverPublicName() ?: 'The driver';
                $body = $driver.' has completed the '.$booking->pickup_at->format('H:i').' '.($booking->displayCustomerName() ?: '').' job';
                \App\Models\WatchdogEvent::log('driver_complete', $body, 'info', $booking);
                $this->adminAlerts->notify('driver_complete', '🏁 '.$driver.' completed the job', $body, 'info', $booking);
            }
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
                ->whereIn('type', ['reminder_24h', 'reminder_2h', 'review_request', 'tip_request'])
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
