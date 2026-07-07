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
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CalendarStats $calendarStats,
        private readonly DriverComplianceService $compliance,
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
                'activeCount' => Booking::active()->count(),
                // Today's booked value (non-cancelled), and jobs booked for the week ahead.
                'todayRevenue' => (float) Booking::whereDate('pickup_at', today())
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
                'remindersToSend' => $this->remindersToSend(),
                'timeMismatches' => $this->timeMismatches(),
            ]);
        }

        if ($user->isDriver()) {
            return view('dashboard.driver', [
                'todayJobs' => Booking::with(['customer', 'vehicleType'])
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
        return Message::query()
            ->whereIn('type', ['reminder_24h', 'reminder_2h'])
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now()->addDays(2)) // due now + next 2 days
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])->where('pickup_at', '>=', now()))
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
                'url' => route('bookings.show', $m->booking),
            ])
            ->values()
            ->all();
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
        return Booking::query()
            ->whereHas('calendarEvent', fn ($q) => $q->whereNotNull('google_event_id'))
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->where('pickup_at', '>=', now()->startOfDay())
            ->with(['calendarEvent', 'customer'])
            ->orderBy('pickup_at')
            ->limit(60)
            ->get()
            ->map(fn (Booking $b) => ['booking' => $b, 'times' => $b->pickupTimeMismatch()])
            ->filter(fn (array $row) => $row['times'] !== [])
            ->map(fn (array $row) => [
                'ref' => $row['booking']->external_reference ?? $row['booking']->reference,
                'customer' => $row['booking']->displayName(),
                'url' => route('bookings.show', $row['booking']),
                'times' => $row['times'],
            ])
            ->values()
            ->take(15)
            ->all();
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
