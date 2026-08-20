<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\Calendar\CalendarStats;
use App\Services\Compliance\DriverComplianceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CalendarStats $calendarStats,
        private readonly DriverComplianceService $compliance,
        private readonly \App\Services\Messaging\BookingNotifier $notifier,
        private readonly \App\Services\Calendar\CalendarTimeSync $timeSync,
    ) {}

    /**
     * Route each role to the appropriate landing view with a scoped data set
     * (principle of least privilege — each role only sees its own data).
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            // "Refresh now" busts the short calendar cache for live figures.
            if ($request->boolean('refresh')) {
                Cache::forget('calendar_stats_events');
            }

            // Headline figures AND the upcoming list come STRAIGHT from the
            // calendar (the operator's source of truth) so they always match it;
            // fall back to the database when the calendar isn't reachable.
            $calendar = $this->calendarStats->counts();
            $revenue = 'COALESCE(final_price, quoted_price, 0)';

            return view('dashboard.admin', [
                'todayCount' => $calendar['jobsToday']
                    ?? Booking::whereDate('pickup_at', today())->count(),
                'pendingCount' => $calendar['awaiting']
                    ?? Booking::where('status', BookingStatus::Pending->value)
                        ->where('pickup_at', '>=', today())
                        ->count(),
                // "Active now" = a driver actually out on a job right now
                // (set off → not yet completed). Recency-guarded so a job left
                // stuck in a driving status days ago can't inflate the figure.
                'activeCount' => Booking::whereIn('status', [
                    BookingStatus::EnRoute->value,
                    BookingStatus::Arrived->value,
                    BookingStatus::Collected->value,
                ])->where('pickup_at', '>=', now()->subHours(12))->count(),
                // Today's booked value (non-cancelled), and jobs booked for the week ahead.
                'todayRevenue' => (float) Booking::whereDate('pickup_at', today())
                    ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->sum(DB::raw($revenue)),
                // New business that CAME IN today — bookings taken today and their
                // value (whenever they're actually travelling). "How did we do today."
                'bookedTodayCount' => Booking::whereDate('created_at', today())
                    ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->count(),
                'bookedTodayRevenue' => (float) Booking::whereDate('created_at', today())
                    ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->sum(DB::raw($revenue)),
                'weekCount' => Booking::whereBetween('pickup_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                    ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->count(),
                'upcoming' => $this->calendarStats->upcoming(10) ?? $this->upcomingFromDatabase(),
                'reviewReminder' => $this->monthlyReviewDue(),
                'complianceAlerts' => $this->complianceAlerts(),
                'driverStatus' => $this->driverStatus(),
                // Cash the drivers should collect today (cash jobs not yet paid).
                'cashToday' => (float) Booking::whereDate('pickup_at', today())
                    ->where('payment_method', 'cash')
                    ->where('payment_status', '!=', 'paid')
                    ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->sum(DB::raw($revenue)),
                'todaySchedule' => $this->calendarStats->jobsOn(today()) ?? $this->jobsFromDatabase(today()),
                'correctedTimes' => session('correctedTimes', []),
                'remindersToSend' => $this->remindersToSend(),
                'reviewsToSend' => $this->reviewsToSend(),
                'timeMismatches' => $this->timeMismatches(),
                'timeline' => $this->nextFourHours(),
                'mapsKey' => \App\Models\Setting::mapsKey(),
            ]);
        }

        if ($user->isDriver()) {
            return view('dashboard.driver', [
                'todayJobs' => Booking::with(['customer', 'vehicleType', 'calendarEvent'])
                    ->forDriver($user->id)
                    ->whereDate('pickup_at', today())
                    ->orderBy('pickup_at')
                    ->get(),
            ]);
        }

        // Corporate client: only their account's bookings.
        $accountIds = $user->corporateAccounts->pluck('id');

        return view('dashboard.corporate', [
            'bookings' => Booking::with(['vehicleType', 'driver'])
                ->whereIn('corporate_account_id', $accountIds)
                ->orderByDesc('pickup_at')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * The reminder worklist for the dashboard — reminders due NOW plus the ones
     * coming up over the next ~2 days, so the box is always populated and the
     * office can send ahead. Each row is flagged due/upcoming.
     *
     * @return array<int, array{ref: string, customer: ?string, pickup: Carbon, due: ?Carbon, is_due: bool, url: string}>
     */
    private function remindersToSend(): array
    {
        // Self-healing: make sure every upcoming job actually has its reminders
        // queued, so tomorrow's always appear here even for imported bookings
        // that never went through the booking form.
        $this->backfillUpcomingReminders();

        return Message::query()
            ->whereIn('type', ['reminder_24h', 'reminder_2h'])
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now()->addDays(2)) // due now + next 2 days
            // A queued reminder stays on the list until it's actually SENT (or the
            // job is cancelled/no-show/complete) — it must NOT quietly drop off the
            // moment the pickup time ticks past. That's what made late-added jobs
            // "miss" their reminder: created due-now, then hidden before anyone
            // sent it. Keep recently-passed pickups visible (last 12h) so a
            // last-minute booking is never lost, without resurfacing ancient ones.
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])->where('pickup_at', '>=', now()->subHours(12)))
            ->with(['booking.customer'])
            ->orderBy('scheduled_for')
            ->get()
            // One row per booking (the 24h and 2h could both be pending).
            ->unique('booking_id')
            ->take(15)
            ->map(fn (Message $m) => [
                'ref' => $m->booking->external_reference ?? $m->booking->reference,
                'customer' => $m->booking->displayName(),
                'pickup' => $m->booking->pickup_at,
                'due' => $m->scheduled_for,
                'is_due' => $m->scheduled_for->lte(now()),
                'url' => route('bookings.show', $m->booking).'#reminders',
            ])
            ->values()
            ->all();
    }

    /**
     * The review worklist for the dashboard — post-journey "leave us a review"
     * requests that are due to be sent by hand, plus any queued for later today.
     * A completed job schedules one ~30 min after drop-off; it stays here until
     * the office sends it and marks it sent.
     *
     * @return array<int, array{ref: string, customer: ?string, pickup: Carbon, due: ?Carbon, is_due: bool, url: string}>
     */
    private function reviewsToSend(): array
    {
        // Self-healing: a completed job that never went through the live
        // Complete-tap (ETO imports, older completions) still gets its review
        // request prepared here, so the worklist is complete.
        $this->backfillCompletedReviews();

        return Message::query()
            ->where('type', 'review_request')
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now()->addDay()) // due now + queued for later today
            // Never chase a review for a cancelled / no-show job — there was no journey.
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', [
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ]))
            ->with(['booking.customer'])
            ->orderBy('scheduled_for')
            ->get()
            ->unique('booking_id')
            ->take(60) // show a good batch — backfilled previous jobs land here too
            ->map(fn (Message $m) => [
                'ref' => $m->booking->external_reference ?? $m->booking->reference,
                'customer' => $m->booking->displayName(),
                'pickup' => $m->booking->pickup_at,
                'due' => $m->scheduled_for,
                'is_due' => $m->scheduled_for->lte(now()),
                'url' => route('bookings.show', $m->booking).'#reminders',
            ])
            ->values()
            ->all();
    }

    /**
     * Queue review requests for jobs completed in the last ~2 days that have a
     * phone but no review request yet — so completions that bypassed the live
     * status flow still show a review to send. Nothing is sent to a customer.
     */
    private function backfillCompletedReviews(): void
    {
        Booking::query()
            ->where('status', BookingStatus::Complete->value)
            ->whereBetween('pickup_at', [now()->subDays((int) config('cet.review_backfill_days', 21)), now()])
            ->whereHas('customer', fn ($q) => $q->whereNotNull('phone'))
            ->whereDoesntHave('messages', fn ($q) => $q->where('type', 'review_request'))
            ->with('customer')
            ->limit(300)
            ->get()
            ->each(fn (Booking $b) => $this->notifier->ensureReviewRequest($b));
    }

    /**
     * The next-4-hours pickup timeline for the ops-room rail: who's due,
     * with which driver, counting down live in the browser.
     *
     * @return array<int, array{ref: string, customer: ?string, pickup_ts: int, time: string, driver: ?string, status: string, status_value: string, url: string}>
     */
    private function nextFourHours(): array
    {
        return Booking::query()
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->whereBetween('pickup_at', [now()->subMinutes(30), now()->addHours(4)])
            ->with(['customer', 'driver.driverProfile'])
            ->orderBy('pickup_at')
            ->limit(12)
            ->get()
            ->map(fn (Booking $b) => [
                'ref' => $b->external_reference ?? $b->reference,
                'customer' => $b->displayName(),
                'pickup_ts' => $b->pickup_at->getTimestamp(),
                'time' => $b->pickup_at->format('H:i'),
                'driver' => $b->driver ? ($b->driver->driverProfile?->callsign ?: Str::before($b->driver->name, ' ')) : null,
                'status' => $b->status->label(),
                'status_value' => $b->status->value,
                'url' => route('bookings.show', $b),
            ])
            ->values()
            ->all();
    }

    /**
     * Queue the 24h/2h reminders for any upcoming, active booking that has a
     * phone number but no reminder yet — so the dashboard's "to send" list is
     * always complete, including tomorrow's jobs and imported bookings. Reminders
     * are only QUEUED for manual sending here; nothing is sent to a customer.
     */
    private function backfillUpcomingReminders(): void
    {
        Booking::query()
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->whereBetween('pickup_at', [now()->subHours(12), now()->addDays(3)])
            ->whereHas('customer', fn ($q) => $q->whereNotNull('phone'))
            ->whereDoesntHave('messages', fn ($q) => $q->whereIn('type', ['reminder_24h', 'reminder_2h']))
            ->with('customer')
            ->limit(100)
            ->get()
            ->each(fn (Booking $b) => $this->notifier->ensureReminders($b));
    }

    /**
     * Upcoming bookings whose pickup time doesn't agree across the three places
     * it appears — the booking (shown here and in the bookings list), the
     * calendar event's slot, and the time printed in the event description — so
     * the office is notified of any drift between the system and the calendar.
     *
     * @return array<int, array{ref: string, customer: ?string, url: string, times: array<string, string>}>
     */
    private function timeMismatches(): array
    {
        return $this->mismatchedBookings()
            ->map(fn (Booking $b) => [
                'ref' => $b->external_reference ?? $b->reference,
                'customer' => $b->displayName(),
                'url' => route('bookings.show', $b),
                'times' => $b->pickupTimeMismatch(),
            ])
            ->values()
            ->take(15)
            ->all();
    }

    /**
     * Upcoming, active bookings whose pickup time doesn't agree with the calendar.
     * Shared by the dashboard notification and the bulk "fix all" action.
     *
     * @return \Illuminate\Support\Collection<int, Booking>
     */
    private function mismatchedBookings(): \Illuminate\Support\Collection
    {
        return Booking::query()
            ->whereHas('calendarEvent', fn ($q) => $q->whereNotNull('google_event_id'))
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->where('pickup_at', '>=', now()->startOfDay())
            ->with(['calendarEvent', 'customer'])
            ->orderBy('pickup_at')
            ->limit(200)
            ->get()
            ->filter(fn (Booking $b) => $b->pickupTimeMismatch() !== [])
            ->values();
    }

    /**
     * One-click calendar sync (manual, admin-initiated). With Google Calendar
     * connected this scans every upcoming linked booking against the LIVE
     * calendar and makes each one match it exactly (time + details — read-only
     * on Google). Without the connection it falls back to aligning against our
     * stored copy of the calendar.
     */
    public function fixTimes(Request $request, \App\Services\Calendar\CalendarTimeSync $sync, \App\Services\Calendar\GoogleCalendarService $google): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($google->configured() && $google->active()) {
            return $this->liveCalendarSync($sync, $google);
        }

        $corrected = $this->mismatchedBookings()
            ->map(function (Booking $b) use ($sync) {
                $from = $b->pickup_at?->format('D d M, H:i');
                $to = $b->calendarEvent->start_at->format('D d M, H:i');
                if (! $sync->alignToCalendarSlot($b)) {
                    return null;
                }
                $b->messages()->whereIn('type', ['reminder_24h', 'reminder_2h'])
                    ->where('status', 'queued')->delete();

                return ['ref' => $b->external_reference ?? $b->reference, 'customer' => $b->displayName(),
                    'url' => route('bookings.show', $b), 'from' => $from, 'to' => $to];
            })
            ->filter()->values()->all();

        return back()
            ->with('correctedTimes', $corrected)
            ->with('status', $corrected !== []
                ? count($corrected).' booking time(s) matched to the calendar.'
                : 'All booking times already match our copy of the calendar.');
    }

    /**
     * Scan upcoming bookings against the live Google Calendar and mirror each
     * one. Includes bookings that were never linked to a stored event (e.g. ETO
     * imports) — the scan matches them to the live event by reference and links
     * them, so "sync" fixes those too, not just already-linked bookings.
     */
    private function liveCalendarSync(\App\Services\Calendar\CalendarTimeSync $sync, \App\Services\Calendar\GoogleCalendarService $google): \Illuminate\Http\RedirectResponse
    {
        $from = now()->startOfDay();
        $to = now()->addDays(14)->endOfDay();

        $bookings = Booking::query()
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->whereBetween('pickup_at', [$from, $to])
            ->with(['calendarEvent', 'customer'])
            ->orderBy('pickup_at')
            ->limit(60)
            ->get();

        // Read the live calendar ONCE for the whole window, then match every
        // booking against it in memory — one API call, not one per booking.
        $calendarId = (string) \App\Models\Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
        $events = $google->eventsBetween($calendarId, $from->copy()->subDays(3), $to->copy()->addDays(3));

        $corrected = [];
        $scanned = 0;
        foreach ($bookings as $b) {
            $wasAt = $b->pickup_at?->format('D d M, H:i');
            $result = $sync->scan($b, $events);
            if ($result['status'] !== 'ok') {
                continue;
            }
            $scanned++;
            if ($result['changes'] === []) {
                continue;
            }

            // Reminders re-queue automatically with the corrected time.
            if (isset($result['changes']['Pickup time'])) {
                $b->messages()->whereIn('type', ['reminder_24h', 'reminder_2h'])
                    ->where('status', 'queued')->delete();
            }

            $corrected[] = [
                'ref' => $b->external_reference ?? $b->reference,
                'customer' => $b->displayName(),
                'url' => route('bookings.show', $b),
                'from' => isset($result['changes']['Pickup time']) ? $wasAt : 'details',
                'to' => $result['changes']['Pickup time']['to'] ?? 'refreshed from the calendar',
            ];
        }

        return back()
            ->with('correctedTimes', $corrected)
            ->with('status', $corrected !== []
                ? "Live calendar sync: {$scanned} upcoming booking(s) scanned, ".count($corrected).' corrected to match.'
                : "Live calendar sync: {$scanned} upcoming booking(s) scanned — everything already matches the calendar.");
    }

    /**
     * Each active driver's live status for the dashboard strip: blocked (expired
     * doc), on-job (a job in progress), available, or off — plus their next job.
     *
     * @return array<int, array{name: string, status: string, next: ?Carbon, reason: ?string}>
     */
    private function driverStatus(): array
    {
        $active = [BookingStatus::Accepted->value, BookingStatus::EnRoute->value, BookingStatus::Collected->value];

        return User::query()
            ->where('is_active', true)
            ->whereHas('driverProfile')
            ->with('driverProfile')
            ->orderBy('name')
            ->get()
            ->map(function (User $d) use ($active) {
                $reason = $this->compliance->blockReason($d);
                $onJob = Booking::where('driver_id', $d->id)->whereIn('status', $active)->exists();
                $next = Booking::where('driver_id', $d->id)->where('pickup_at', '>=', now())
                    ->orderBy('pickup_at')->value('pickup_at');

                $status = match (true) {
                    $reason !== null => 'blocked',
                    $onJob => 'on-job',
                    (bool) $d->driverProfile?->is_available => 'available',
                    default => 'off',
                };

                return ['name' => $d->name, 'status' => $status, 'next' => $next, 'reason' => $reason];
            })
            ->all();
    }

    /**
     * A full-detail list of the jobs on a given day (default today), straight
     * from the calendar — every field, so the operator sees exactly what each
     * job is. Falls back to the database when the calendar can't be read.
     */
    public function day(Request $request): View
    {
        $day = ($request->date('date') ?? today())->startOfDay();
        $jobs = $this->calendarStats->jobsOn($day) ?? $this->jobsFromDatabase($day);

        return view('dashboard.jobs', ['day' => $day, 'jobs' => $jobs]);
    }

    /** Day's jobs from the database, mapped to the same detail rows. */
    private function jobsFromDatabase(Carbon $day): array
    {
        return Booking::with(['customer', 'vehicleType', 'driver', 'calendarEvent'])
            ->whereDate('pickup_at', $day)
            ->orderBy('pickup_at')
            ->get()
            ->map(fn (Booking $b) => [
                'ref' => $b->external_reference ?? $b->reference,
                'pickup' => $b->pickup_at,
                'customer' => $b->customer?->name,
                'vehicle' => $b->vehicleType?->name ?? '—',
                'driver' => $b->driver?->name ?? '—',
                'status' => $b->status?->label() ?? 'Scheduled',
                'url' => route('bookings.show', $b),
                'title' => trim((string) ($b->calendarEvent?->title ?? ''), '*'),
                'location' => $b->pickup_address,
                'description' => (string) ($b->calendarEvent?->description ?? ''),
                'event_id' => null,
                'flight' => $b->flight_number,
            ])
            ->all();
    }

    /**
     * Active drivers currently blocked by an expired document — surfaced on the
     * dashboard so lapses are caught before they hit despatch.
     *
     * @return array<int, array{name: string, reason: string}>
     */
    private function complianceAlerts(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('driverProfile')
            ->with('driverProfile.defaultVehicle')
            ->get()
            ->map(fn (User $d) => ['name' => $d->name, 'reason' => $this->compliance->blockReason($d)])
            ->filter(fn ($a) => $a['reason'] !== null)
            ->values()
            ->all();
    }

    /**
     * Whether the monthly review (run 4th-to-4th) is due — i.e. it's the 4th or
     * later and no fresh ETO export has been imported since the most recent 4th.
     * Reminds the operator to send over a new ETO CSV for the period's figures.
     */
    private function monthlyReviewDue(): bool
    {
        $now = now();
        // The most recent "4th of the month at 00:00".
        $mostRecent4th = ($now->day >= 4 ? $now->copy()->startOfMonth() : $now->copy()->subMonthNoOverflow()->startOfMonth())
            ->addDays(3);

        $last = Setting::get('last_eto_import_at');

        return $last === null || Carbon::parse($last)->lt($mostRecent4th);
    }

    /**
     * Upcoming jobs from the database, mapped to the SAME display rows the
     * calendar produces — used only when the calendar can't be read.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upcomingFromDatabase(): array
    {
        return Booking::with(['customer', 'vehicleType', 'driver'])
            ->where('pickup_at', '>=', now())
            ->orderBy('pickup_at')
            ->limit(10)
            ->get()
            ->map(fn (Booking $b) => [
                'ref' => $b->external_reference ?? $b->reference,
                'pickup' => $b->pickup_at,
                'customer' => $b->customer?->name,
                'vehicle' => $b->vehicleType?->name ?? '—',
                'driver' => $b->driver?->name ?? '—',
                'status' => $b->status?->label() ?? 'Scheduled',
                'url' => route('bookings.show', $b),
            ])
            ->all();
    }
}
