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

    public function __construct(private readonly WebPushService $push) {}

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
                BookingStatus::NoShow->value, BookingStatus::Pending->value,
            ])
            ->whereNotNull('driver_id')
            // Today's jobs, plus late-running jobs from yesterday evening and
            // early jobs just past midnight (BST-safe: everything compares in
            // the app clock, never raw UTC).
            ->whereBetween('pickup_at', [now()->subDay(), now()->addDay()])
            ->with(['driver', 'customer'])
            ->orderBy('pickup_at')
            ->get();

        foreach ($jobs as $booking) {
            $sent += $this->evaluate($booking);
        }

        return $sent;
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
            if (now()->gte($booking->pickup_at->copy()->subMinutes(10))) {
                $sent += $this->nudge($booking, 'set_off_urgent',
                    'URGENT: '.$booking->pickup_at->format('H:i').' pickup — set off now',
                    'URGENT: '.$booking->pickup_at->format('H:i').' pickup at '.$this->shortAddress($booking->pickup_address).' — set off now',
                    severity: 'critical');
            } elseif (now()->gte($booking->pickup_at->copy()->subMinutes($this->setOffLeadMinutes($booking)))) {
                $sent += $this->nudge($booking, 'set_off',
                    '🚗 Time to set off — pickup at '.$booking->pickup_at->format('H:i'),
                    'Time to set off for '.$this->shortAddress($booking->pickup_address).' — pickup at '.$booking->pickup_at->format('H:i'),
                    severity: 'warning');
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
        } elseif ($pings->isNotEmpty() && $status->isActive()) {
            // GPS gone stale mid-job — log once for the alerts feed.
            $this->logOnce($booking, 'gps_stale', 'warning',
                'GPS stale for '.($booking->driver?->name ?? 'driver').' on the '.$booking->pickup_at->format('H:i').' job');
        }

        // ── 6: complete fallback — pure clock, works with dead GPS ──────────
        if ($status === BookingStatus::Collected
            && now()->gte($booking->pickup_at->copy()->addMinutes($this->estimatedDurationMinutes($booking) + 45))) {
            $sent += $this->nudge($booking, 'complete_fallback',
                '🏁 Job still open',
                'Is the '.$booking->pickup_at->format('H:i').' job complete? Update the status',
                severity: 'warning');
        }

        return $sent;
    }

    /**
     * Rule 1 lead time: drive time from the driver's last known position to
     * the pickup + 10 min buffer, clamped 20–60. Flat 30 without GPS/coords.
     */
    public function setOffLeadMinutes(Booking $booking): int
    {
        $pickup = $this->coords($booking, 'pickup');
        $last = $booking->driver_id
            ? DriverLocation::where('driver_id', $booking->driver_id)
                ->where('captured_at', '>=', now()->subHours(12))
                ->latest('captured_at')->first()
            : null;

        if (! $pickup || ! $last) {
            return 30;
        }

        $drive = Geo::estimateDriveMinutes((float) $last->latitude, (float) $last->longitude, $pickup[0], $pickup[1]);

        return max(20, min(60, $drive + 10));
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

    /** Journey duration for the complete fallback: meta, else estimate, else 60. */
    public function estimatedDurationMinutes(Booking $booking): int
    {
        $meta = (int) ($booking->meta['duration_minutes'] ?? 0);
        if ($meta > 0) {
            return $meta;
        }

        $geo = $booking->meta['geo'] ?? [];
        if (isset($geo['pickup'][0], $geo['dropoff'][0])) {
            return Geo::estimateDriveMinutes(
                (float) $geo['pickup'][0], (float) $geo['pickup'][1],
                (float) $geo['dropoff'][0], (float) $geo['dropoff'][1],
            );
        }

        return 60;
    }

    private function shortAddress(?string $address): string
    {
        return Str::limit(Str::before((string) $address, ','), 40, '');
    }
}
