<?php

namespace App\Services\Watchdog;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\JobNudge;
use App\Models\Setting;
use App\Models\WatchdogEvent;
use App\Services\Push\WebPushService;
use App\Support\Geo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The status watchdog — runs every minute and keeps jobs moving without the
 * office having to chase drivers:
 *
 *   1. "Time to set off" when the pickup is a drive-time away (allocated OR
 *      accepted — anything before Set off), escalating 10 min before pickup.
 *   2. Geofence detections: looks-like-you've-arrived / POB / job-done nudges
 *      from the GPS stream, jitter-proofed (≥2 consecutive qualifying pings)
 *      and skipped entirely while GPS is stale (> 3 min old).
 *   3. A time-based "is the job complete?" fallback that works with dead GPS.
 *
 * Idempotent by design: every send is recorded in job_nudges and each nudge
 * type fires at most twice per job (the repeat ≥5 min after the first, only
 * if the state hasn't moved on). Every decision is logged to watchdog_events
 * for the dashboard alerts feed.
 *
 * Pickup/drop-off coordinates aren't stored on bookings (Places saves text),
 * so the watchdog geocodes them lazily into booking.meta['geo'] — CLI-side,
 * where the network is reliable. When geocoding is unavailable the geofence
 * rules skip and the time-based rules still run, so the watchdog degrades
 * gracefully to "reminders by clock" rather than breaking.
 */
class StatusWatchdog
{
    /** Geofence radius for arrived/complete detection (metres). */
    public const GEOFENCE_M = 150;

    /** GPS older than this is stale — geofence rules don't run. */
    public const STALE_MINUTES = 3;

    /** A nudge repeats at most once, this long after the first send. */
    public const REPEAT_AFTER_MINUTES = 5;

    public const MAX_SENDS = 2;

    /**
     * How long after a pickup a not-yet-started job is still "present". Past
     * this, an Allocated/Accepted/Pending job is treated as history — the office
     * runs off the calendar and marks these complete by hand, and they must not
     * keep firing "set off"/"unallocated" alerts. Jobs already in progress
     * (En Route / Arrived / POB) stay live regardless of how long ago pickup was.
     */
    public const PAST_GRACE_MINUTES = 30;

    /** A driver nudge is "unacted" this long after its second send. */
    public const UNACTED_AFTER_MINUTES = 5;

    /** Driver-nudge types → the statuses they nag about + the action wording. */
    private const UNACTED_MAP = [
        'set_off' => [[BookingStatus::Allocated, BookingStatus::Accepted], 'set off'],
        'set_off_urgent' => [[BookingStatus::Allocated, BookingStatus::Accepted], 'set off'],
        'arrived_detect' => [[BookingStatus::EnRoute], 'tapped Arrived'],
        'pob_detect' => [[BookingStatus::Arrived], 'tapped POB'],
        'complete_detect' => [[BookingStatus::Collected], 'completed'],
        'complete_fallback' => [[BookingStatus::Collected], 'completed'],
    ];

    public function __construct(
        private readonly WebPushService $push,
        private readonly AdminAlerts $admins,
    ) {}

    /**
     * Evaluate every live job in the window. Returns the number of nudges sent
     * (for the command's output).
     */
    public function run(): int
    {
        $sent = 0;

        $jobs = Booking::query()
            ->whereNotIn('status', [
                BookingStatus::Complete->value, BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ])
            // Today's jobs, plus late-running jobs from yesterday evening and
            // early jobs just past midnight (BST-safe: everything compares in
            // the app clock, never raw UTC).
            ->whereBetween('pickup_at', [now()->subDay(), now()->addDay()])
            ->with(['driver', 'customer'])
            ->orderBy('pickup_at')
            ->get();

        foreach ($jobs as $booking) {
            // Only chase PRESENT/FUTURE jobs. A past pickup that never got moving
            // is history — don't notify about it (even allocated ones).
            if ($this->isPastJob($booking)) {
                continue;
            }
            if ($booking->driver_id) {
                $sent += $this->evaluate($booking);
            }
            $sent += $this->adminPass($booking);
        }

        $sent += $this->remindersPass();

        return $sent;
    }

    /**
     * A job that's history — its pickup is well in the past and it never got
     * moving (still Pending/Allocated/Accepted). These are handled off the
     * calendar and must not fire nudges/escalations. A job already in progress
     * (En Route / Arrived / POB) is always "live", however long ago pickup was,
     * so its complete-detection still runs.
     */
    public function isPastJob(Booking $booking): bool
    {
        if (in_array($booking->status, [
            BookingStatus::EnRoute, BookingStatus::Arrived, BookingStatus::Collected,
        ], true)) {
            return false;
        }

        return $booking->pickup_at
            && $booking->pickup_at->lt(now()->subMinutes(self::PAST_GRACE_MINUTES));
    }

    /** Run all rules for one job. */
    public function evaluate(Booking $booking): int
    {
        $sent = 0;
        $status = $booking->status;
        $pings = $this->recentPings($booking);
        $stale = $this->gpsIsStale($pings);

        // ── 1+2: set off (anything before En Route) ─────────────────────────
        if (in_array($status, [BookingStatus::Allocated, BookingStatus::Accepted], true)) {
            $airportLanding = $this->isAirportPickup($booking) ? $this->flightLandingAt($booking) : null;

            if (now()->gte($booking->pickup_at->copy()->subMinutes(10))) {
                $sent += $this->nudge($booking, 'set_off_urgent',
                    'URGENT: '.$booking->pickup_at->format('H:i').' pickup — set off now',
                    'URGENT: '.$booking->pickup_at->format('H:i').' pickup at '.$this->shortAddress($booking->pickup_address).' — set off now',
                    severity: 'critical');
            } elseif (now()->gte($this->setOffDeadline($booking))) {
                // For an airport pickup, spell out the landing time and the "be
                // there by" so the driver knows why it's time to go NOW.
                [$title, $body] = $airportLanding
                    ? ['🛬 Set off for '.$this->shortAddress($booking->pickup_address).' — lands '.$airportLanding->format('H:i'),
                        'Flight lands '.$airportLanding->format('H:i').' — set off now to reach '.$this->shortAddress($booking->pickup_address).' by '.$airportLanding->copy()->addMinutes(self::AIRPORT_ARRIVE_AFTER_LANDING)->format('H:i')]
                    : ['🚗 Time to set off — pickup at '.$booking->pickup_at->format('H:i'),
                        'Time to set off for '.$this->shortAddress($booking->pickup_address).' — pickup at '.$booking->pickup_at->format('H:i')];
                $sent += $this->nudge($booking, 'set_off', $title, $body, severity: 'warning');
            }
        }

        // ── Geofence detections — only with fresh GPS and known coords ──────
        if (! $stale) {
            $pickup = $this->coords($booking, 'pickup');
            $dropoff = $this->coords($booking, 'dropoff');

            if ($status === BookingStatus::EnRoute && $pickup
                && $this->dwellingWithin($pings, $pickup, self::GEOFENCE_M, minutes: 2)) {
                $sent += $this->nudge($booking, 'arrived_detect',
                    '📍 Looks like you’ve arrived',
                    'Looks like you’ve arrived — tap Arrived');
            }

            if ($status === BookingStatus::Arrived && $pickup
                && $this->movingAwayFrom($pings, $pickup, minMph: 15)) {
                $sent += $this->nudge($booking, 'pob_detect',
                    '🧍 Passenger on board?',
                    'Passenger on board? Tap POB');
            }

            if ($status === BookingStatus::Collected && $dropoff
                && $this->dwellingWithin($pings, $dropoff, self::GEOFENCE_M, minutes: 3, maxMph: 5)) {
                $sent += $this->nudge($booking, 'complete_detect',
                    '🏁 Job done?',
                    'Job done? Tap Complete');
            }
        }
        // NB: we deliberately do NOT alert when GPS goes stale mid-job. A web app
        // can't track once the driver backgrounds the app, so a "GPS lost" would
        // fire on almost every job — pure noise. The geofence nudges above simply
        // skip while GPS is stale; the clock-based fallbacks still cover the job.

        // ── 6: complete fallback — pure clock, works with dead GPS. Anchored to
        //    when the customer got in the car (POB) so we never nag while the
        //    driver's still waiting at arrivals; only once the drive home should
        //    be done (see completeBy).
        if ($status === BookingStatus::Collected && now()->gte($this->completeBy($booking))) {
            $sent += $this->nudge($booking, 'complete_fallback',
                '🏁 Job still open',
                'Is the '.$booking->pickup_at->format('H:i').' job complete? Update the status',
                severity: 'warning');
        }

        return $sent;
    }

    /**
     * Buzz the office when a customer WhatsApp reminder is DUE to send, so it
     * doesn't get forgotten. Once per reminder (24h and 2h each), and only when
     * it's actually due — the reminder's scheduled time already sits inside the
     * 08:00–22:00 send window, so nothing fires overnight.
     */
    private function remindersPass(): int
    {
        $sent = 0;

        $due = \App\Models\Message::query()
            ->whereIn('type', ['reminder_24h', 'reminder_2h'])
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            // Only for jobs still to run (or only just gone) — never nudge about a
            // reminder for a pickup that's already well in the past. Mirrors the
            // dashboard reminders list so the two always agree.
            ->whereHas('booking', fn ($q) => $q
                ->whereNotIn('status', [
                    BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
                ])
                ->where('pickup_at', '>=', now()->subHours(12)))
            ->with('booking.customer')
            ->limit(40)
            ->get();

        foreach ($due as $m) {
            $booking = $m->booking;
            if (! $booking) {
                continue;
            }
            $name = $booking->displayName() ?: ($booking->customer?->name ?? 'a customer');

            $sent += (int) $this->admins->send(
                $booking,
                'reminder_due_'.$m->type,     // deduped once per booking × reminder type
                'reminder_due',
                '📲 Send reminder — '.$name,
                $name.' · '.$booking->pickup_at->format('D H:i').' pickup — open & send it on WhatsApp.',
                severity: 'info',
                maxSends: 1,
            );
        }

        return $sent;
    }

    /**
     * The admin escalation tier — pushes to the office when a job needs a
     * human: nobody allocated close to pickup, a driver ignoring nudges, or
     * GPS lost mid-job. Cadence/caps live in AdminAlerts (job_nudges rows
     * with recipient_type 'admin').
     */
    public function adminPass(Booking $booking): int
    {
        $sent = 0;
        $time = $booking->pickup_at->format('H:i');
        $where = $this->shortAddress($booking->pickup_address);

        // Unallocated with pickup ≤ 2h away — critical, every 30 min until
        // someone takes it.
        if (($booking->status === BookingStatus::Pending || ! $booking->driver_id)
            && ! $booking->status->isTerminal()
            && now()->gte($booking->pickup_at->copy()->subHours(2))) {
            $sent += (int) $this->admins->send($booking, 'admin_unallocated', 'unallocated',
                '🔴 Unallocated: '.$time.' '.$where,
                'Unallocated: '.$time.' pickup at '.$where.' — allocate a driver',
                severity: 'critical', maxSends: null, repeatMinutes: 30);

            return $sent; // no driver → nothing below applies
        }

        if (! $booking->driver_id) {
            return $sent;
        }

        // Driver nudge sent twice and still no reaction 5 min later.
        foreach (self::UNACTED_MAP as $type => [$statuses, $action]) {
            if (! in_array($booking->status, $statuses, true)) {
                continue;
            }
            $last = JobNudge::where('booking_id', $booking->id)
                ->where('nudge_type', $type)->where('recipient_type', 'driver')
                ->orderByDesc('sent_at')->get();
            if ($last->count() < self::MAX_SENDS
                || $last->first()->sent_at->gt(now()->subMinutes(self::UNACTED_AFTER_MINUTES))) {
                continue;
            }

            $driver = $booking->driver?->name ?? 'Driver';
            $sent += (int) $this->admins->send($booking, 'admin_unacted_'.$type, 'unacted',
                '🔴 '.$driver." hasn't ".$action,
                $driver." hasn't ".$action.' for the '.$time.' '.$where.' job',
                severity: 'critical');
        }

        // Child seat needed but no driver has confirmed picking it up from the
        // office. Fires from 2h before pickup until someone confirms — capped so
        // it reminds a few times without spamming. Safety-critical, so it runs
        // even after set-off (a driver who left without the seat needs chasing).
        if ($booking->hasChildSeat()
            && ! $booking->anyChildSeatConfirmed()
            && now()->gte($booking->pickup_at->copy()->subHours(2))) {
            $seats = $booking->displayChildSeats();
            $sent += (int) $this->admins->send($booking, 'admin_child_seats', 'child_seats',
                '🚼 Child seat not confirmed — '.$time.' '.$where,
                'Driver hasn’t confirmed collecting the '.$seats.' for the '.$time.' '.$where.' job — check they’ve got it.',
                severity: 'warning', maxSends: 3, repeatMinutes: 30);
        }

        // NB: no "GPS lost mid-job" office alert. A web app stops sending GPS the
        // moment the driver backgrounds the app, so this would cry wolf on nearly
        // every job. (If we ever ship a native app with true background tracking,
        // re-enable it — then a GPS drop actually means something.)

        return $sent;
    }

    /** Be at the airport this many minutes after the flight lands. */
    private const AIRPORT_ARRIVE_AFTER_LANDING = 20;

    /**
     * Rule 1 lead time: drive time from the driver's last known position to the
     * pickup + a 5 min buffer, so a 30-min-away job is chased ~35 min before
     * pickup and a 20-min-away one ~25 min before. Clamped 15–90 (far airport
     * pickups need a longer lead). Flat 30 without GPS/coords.
     */
    public function setOffLeadMinutes(Booking $booking): int
    {
        // Prefer the driver's live position (drive + 5 min buffer).
        $gpsDrive = $this->driveMinutesToPickup($booking);
        if ($gpsDrive !== null) {
            return max(15, min(150, $gpsDrive + 5));
        }

        // No live GPS → estimate from the Sheffield base and add the base buffer
        // ("Sheffield + 10 mins"), so a driver who hasn't shared GPS still gets
        // chased at the right time — a distant pickup gets a long lead, a local
        // one a short one.
        $baseDrive = $this->baseToPickupMinutes($booking);
        if ($baseDrive !== null) {
            return max(15, min(150, $baseDrive + (int) config('cet.base.buffer_minutes', 10)));
        }

        // No coords at all (couldn't geocode the pickup). An airport pickup still
        // needs a generous head start; anything else falls back to a flat 30.
        if ($this->isAirportPickup($booking)) {
            return max(30, min(150, $this->estimatedDurationMinutes($booking) + 5));
        }

        return 30;
    }

    /**
     * Estimated driving minutes from the Firth Park base to the pickup — the
     * fallback used when a driver hasn't shared live GPS yet. Free straight-line
     * estimate; null if we can't geocode the pickup or have no base configured.
     */
    private function baseToPickupMinutes(Booking $booking): ?int
    {
        $base = $this->baseCoords();
        $pickup = $this->coords($booking, 'pickup');
        if (! $base || ! $pickup) {
            return null;
        }

        return Geo::estimateDriveMinutes($base[0], $base[1], $pickup[0], $pickup[1]);
    }

    /** @return array{0: float, 1: float}|null */
    private function baseCoords(): ?array
    {
        $lat = config('cet.base.lat');
        $lng = config('cet.base.lng');

        return ($lat !== null && $lng !== null) ? [(float) $lat, (float) $lng] : null;
    }

    /**
     * The absolute time by which the driver must have set off.
     *
     * For an AIRPORT PICKUP with a known landing time, this is driven by the
     * flight, NOT the scheduled pickup: the driver must be at the airport ~20
     * min after landing, so they must leave (drive time) before that. Uses the
     * live landing time, so a delayed flight pushes it back and an early one
     * pulls it forward. Drive time is the driver→airport estimate from GPS, or
     * the job's own route length as a proxy (airport→home ≈ home→airport) when
     * we don't have a live position. Everything else is pickup − lead.
     */
    public function setOffDeadline(Booking $booking): Carbon
    {
        if ($this->isAirportPickup($booking) && ($landing = $this->flightLandingAt($booking))) {
            $drive = $this->driveMinutesToPickup($booking)
                ?? $this->baseToPickupMinutes($booking)
                ?? $this->estimatedDurationMinutes($booking);

            return $landing->copy()
                ->addMinutes(self::AIRPORT_ARRIVE_AFTER_LANDING)
                ->subMinutes($drive + 5); // 5-min safety so they arrive a touch early
        }

        return $booking->pickup_at->copy()->subMinutes($this->setOffLeadMinutes($booking));
    }

    /** Estimated driving minutes from the driver's last position to the pickup, or null. */
    private function driveMinutesToPickup(Booking $booking): ?int
    {
        $pickup = $this->coords($booking, 'pickup');
        $last = $booking->driver_id
            ? DriverLocation::where('driver_id', $booking->driver_id)
                ->where('captured_at', '>=', now()->subHours(12))
                ->latest('captured_at')->first()
            : null;

        if (! $pickup || ! $last) {
            return null;
        }

        return Geo::estimateDriveMinutes((float) $last->latitude, (float) $last->longitude, $pickup[0], $pickup[1]);
    }

    /* ── Geofence primitives ──────────────────────────────────────────────── */

    /** @return Collection<int, DriverLocation> newest first */
    private function recentPings(Booking $booking): Collection
    {
        return DriverLocation::where('booking_id', $booking->id)
            ->where('captured_at', '>=', now()->subMinutes(15))
            ->orderByDesc('captured_at')
            ->limit(40)
            ->get();
    }

    private function gpsIsStale(Collection $pings): bool
    {
        $latest = $pings->first();

        return ! $latest || $latest->captured_at->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * True when the LATEST run of consecutive pings inside the radius spans at
     * least $minutes and contains ≥2 pings (a single stray ping never counts).
     * With $maxMph set, every ping in the run must also be at/below that speed.
     *
     * @param  array{0: float, 1: float}  $target
     */
    private function dwellingWithin(Collection $pings, array $target, int $radiusM, int $minutes, ?float $maxMph = null): bool
    {
        if ($pings->count() < 2) {
            return false;
        }

        $run = [];
        foreach ($pings as $ping) { // newest → oldest
            $inside = Geo::haversineMeters((float) $ping->latitude, (float) $ping->longitude, $target[0], $target[1]) <= $radiusM;
            if (! $inside) {
                break; // run must be consecutive and end at the newest ping
            }
            if ($maxMph !== null && $this->pingMph($ping, $pings) > $maxMph) {
                break;
            }
            $run[] = $ping;
        }

        if (count($run) < 2) {
            return false;
        }

        $span = end($run)->captured_at->diffInSeconds($run[0]->captured_at, true);

        return $span >= $minutes * 60;
    }

    /**
     * True when the last two ping intervals BOTH move away from the target
     * faster than $minMph — two consecutive qualifying pings, so one jittery
     * fix can't fake a departure.
     *
     * @param  array{0: float, 1: float}  $target
     */
    private function movingAwayFrom(Collection $pings, array $target, float $minMph): bool
    {
        if ($pings->count() < 3) {
            return false;
        }

        [$new, $mid, $old] = [$pings[0], $pings[1], $pings[2]];

        $d = fn (DriverLocation $p) => Geo::haversineMeters((float) $p->latitude, (float) $p->longitude, $target[0], $target[1]);

        if (! ($d($new) > $d($mid) && $d($mid) > $d($old))) {
            return false;
        }

        foreach ([[$mid, $new], [$old, $mid]] as [$a, $b]) {
            $mph = $b->speed !== null
                ? (float) $b->speed * 2.23694 // geolocation speed is m/s
                : Geo::speedMph((float) $a->latitude, (float) $a->longitude, $a->captured_at,
                    (float) $b->latitude, (float) $b->longitude, $b->captured_at);
            if ($mph === null || $mph <= $minMph) {
                return false;
            }
        }

        return true;
    }

    /** Speed of one ping in mph — device-reported, else derived from its neighbour. */
    private function pingMph(DriverLocation $ping, Collection $pings): float
    {
        if ($ping->speed !== null) {
            return (float) $ping->speed * 2.23694;
        }

        $idx = $pings->search(fn ($p) => $p->id === $ping->id);
        $neighbour = $pings[$idx + 1] ?? null;
        if (! $neighbour) {
            return 0.0;
        }

        return Geo::speedMph(
            (float) $neighbour->latitude, (float) $neighbour->longitude, $neighbour->captured_at,
            (float) $ping->latitude, (float) $ping->longitude, $ping->captured_at,
        ) ?? 0.0;
    }

    /* ── Sending & idempotency ────────────────────────────────────────────── */

    /** Send a driver nudge if this type still has sends left. Returns 1 if sent. */
    private function nudge(Booking $booking, string $type, string $title, string $body, string $severity = 'info'): int
    {
        if (! $booking->driver || ! $this->shouldSend($booking, $type)) {
            return 0;
        }

        $this->push->sendToUser($booking->driver, $title, $body, [
            'url' => route('driver.job', $booking),
            'tag' => 'nudge-'.$type.'-'.$booking->id,
        ]);

        JobNudge::create([
            'booking_id' => $booking->id,
            'nudge_type' => $type,
            'recipient_type' => 'driver',
            'recipient_id' => $booking->driver_id,
            'sent_at' => now(),
            'channel' => 'push',
            'created_at' => now(),
        ]);

        WatchdogEvent::log('nudge_'.$type, $body, $severity, $booking);

        return 1;
    }

    /** Max 2 sends per type per job; the repeat ≥5 min after the first. */
    private function shouldSend(Booking $booking, string $type): bool
    {
        $previous = JobNudge::where('booking_id', $booking->id)
            ->where('nudge_type', $type)
            ->where('recipient_type', 'driver')
            ->orderBy('sent_at')
            ->get();

        if ($previous->count() >= self::MAX_SENDS) {
            return false;
        }

        if ($previous->count() === 1) {
            return $previous->first()->sent_at->lte(now()->subMinutes(self::REPEAT_AFTER_MINUTES));
        }

        return true;
    }

    /** Log a watchdog observation once per job (not a push — feed only). */
    private function logOnce(Booking $booking, string $type, string $severity, string $title): void
    {
        $exists = WatchdogEvent::where('booking_id', $booking->id)->where('event_type', $type)->exists();
        if (! $exists) {
            WatchdogEvent::log($type, $title, $severity, $booking);
        }
    }

    /* ── Coordinates ──────────────────────────────────────────────────────── */

    /**
     * Pickup/drop-off coordinates for geofencing, from booking.meta['geo'],
     * geocoding lazily on first need. Returns [lat, lng] or null (→ geofence
     * rules skip; the time-based rules carry the job).
     *
     * @return array{0: float, 1: float}|null
     */
    public function coords(Booking $booking, string $which): ?array
    {
        $geo = $booking->meta['geo'] ?? [];
        if (isset($geo[$which][0], $geo[$which][1])) {
            return [(float) $geo[$which][0], (float) $geo[$which][1]];
        }

        // Failed recently? Don't hammer the API every minute — retry daily.
        $failedAt = $geo['failed_at'] ?? null;
        if ($failedAt && now()->parse($failedAt)->gt(now()->subDay())) {
            return null;
        }

        $address = $which === 'pickup' ? $booking->pickup_address : $booking->destination_address;
        $point = $this->geocode($address);

        $geo[$which] = $point; // null is stored too, but failed_at drives retry
        if ($point === null) {
            $geo['failed_at'] = now()->toIso8601String();
        }
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['geo' => $geo])])->save();

        return $point;
    }

    /** @return array{0: float, 1: float}|null */
    private function geocode(?string $address): ?array
    {
        $key = Setting::mapsKey();
        if (blank($address) || Str::lower(trim($address)) === 'unknown' || ! $key) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'region' => 'gb',
                'key' => $key,
            ]);
            $loc = $response->json('results.0.geometry.location');

            return isset($loc['lat'], $loc['lng']) ? [(float) $loc['lat'], (float) $loc['lng']] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The earliest time we should ask "is this job complete?".
     *
     * Best anchor is when the customer actually got in the car (POB) — from
     * there it's just the drive home, which naturally covers a long airport
     * wait (the nudge can't fire before POB anyway). With no POB timestamp we
     * estimate from the schedule: an airport PICKUP gets a generous allowance
     * (landing → clear the terminal → drive), using the flight's landing time
     * when we know it, else a fixed 2-hour window; a normal drop just gets the
     * drive plus a short buffer.
     */
    public function completeBy(Booking $booking): Carbon
    {
        // The real pickup → drop-off drive time, then a 30-min buffer on top,
        // so "is the job complete?" never fires until the journey should truly
        // be finished (drop-off + settle) — not while they're still driving.
        $drive = $this->estimatedDurationMinutes($booking);

        if ($pob = $this->pobAt($booking)) {
            return $pob->copy()->addMinutes($drive + self::COMPLETE_BUFFER_MINUTES);
        }

        if ($this->isAirportPickup($booking)) {
            $landing = $this->flightLandingAt($booking);
            $anchor = ($landing && $landing->gt($booking->pickup_at)) ? $landing : $booking->pickup_at;

            return $anchor->copy()->addMinutes(120 + $drive); // clear the airport + drive home
        }

        return $booking->pickup_at->copy()->addMinutes($drive + self::COMPLETE_BUFFER_MINUTES);
    }

    /** Buffer added after the pickup → drop-off drive before asking "complete?". */
    public const COMPLETE_BUFFER_MINUTES = 30;

    /** When the driver marked the customer on-board (POB), or null. */
    private function pobAt(Booking $booking): ?Carbon
    {
        return $booking->statusHistory()
            ->where('to_status', BookingStatus::Collected->value)
            ->latest('created_at')->first()?->created_at;
    }

    /** True when the pickup itself is at an airport (an arrival job). */
    private function isAirportPickup(Booking $booking): bool
    {
        if (Str::startsWith(Str::lower((string) ($booking->meta['journey_label'] ?? '')), 'arrival')) {
            return true;
        }

        return Str::contains(Str::lower((string) $booking->pickup_address), 'airport');
    }

    /** The flight's landing time from the monitor (estimated, else scheduled), or null. */
    private function flightLandingAt(Booking $booking): ?Carbon
    {
        $monitor = \App\Models\FlightMonitor::where('booking_id', $booking->id)->first();
        $when = $monitor?->estimated_arrival ?? $monitor?->scheduled_arrival;

        if (! $when) {
            return null;
        }

        try {
            return $when instanceof Carbon ? $when : Carbon::parse($when);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Journey duration (pickup → drop-off) for the complete fallback. Uses a
     * stored duration if we have one, else the real pickup/drop-off coords
     * (geocoded once, then the free straight-line estimate) — a flat 60-min
     * default made long runs like Sheffield → Manchester (~1h40) nag to
     * complete far too early. Falls back to a generous 90 min only when we
     * genuinely can't geocode, so the nudge stays conservative.
     */
    public function estimatedDurationMinutes(Booking $booking): int
    {
        $meta = (int) ($booking->meta['duration_minutes'] ?? 0);
        if ($meta > 0) {
            return $meta;
        }

        $pickup = $this->coords($booking, 'pickup');
        $dropoff = $this->coords($booking, 'dropoff');
        if ($pickup && $dropoff) {
            return Geo::estimateDriveMinutes($pickup[0], $pickup[1], $dropoff[0], $dropoff[1]);
        }

        return 90;
    }

    private function shortAddress(?string $address): string
    {
        return Str::limit(Str::before((string) $address, ','), 40, '');
    }
}
