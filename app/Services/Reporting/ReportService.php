<?php

namespace App\Services\Reporting;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revenue & operations reporting. All figures are drawn from COMPLETED bookings,
 * valuing each at final_price (falling back to quoted_price). Revenue is the
 * SQL-portable expression COALESCE(final_price, quoted_price, 0).
 */
class ReportService
{
    private const REVENUE = 'COALESCE(final_price, quoted_price, 0)';

    /**
     * Revenue-earning jobs in the period: every booking whose pickup falls in the
     * window and that WASN'T cancelled or a no-show. We deliberately don't require
     * status = Complete — operators rarely hand-mark jobs complete, so a job that
     * has run (pickup in the past, not cancelled) counts toward revenue.
     */
    private function completed(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Booking::query()
            ->where(function (Builder $q) {
                // A job that has run (not cancelled, pickup passed) …
                $q->where(fn (Builder $qq) => $qq
                    ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    ->where('pickup_at', '<=', now()))
                    // … OR a charged cancellation, whose fee is earned at cancel time.
                    ->orWhereNotNull('meta->cancellation->fee');
            })
            ->whereBetween('pickup_at', [$start, $end]);
    }

    /**
     * One business in detail: every customer who's travelled with them, ranked by
     * booking count, with each customer's spend and last trip — so a repeat client
     * is obvious at a glance.
     *
     * A booking belongs to the business when it's tagged directly, when the
     * traveller's customer record is, OR when it was booked by / for the business
     * (booker name, contact name, or the company's email domain) — so a JELD-WEN
     * passenger the office never hand-tagged still rolls up here.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function businessCustomers(int $accountId): Collection
    {
        $map = $this->corporateNameMap();

        return Booking::query()
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->with('customer')
            ->get()
            ->filter(fn (Booking $b) => $this->accountIdForBooking($b, $map) === $accountId)
            ->groupBy('customer_id')
            ->map(function (Collection $group) {
                $lastAt = $group->pluck('pickup_at')->filter()->max();

                return [
                    'customer' => $group->first()->customer,
                    'name' => $group->first()->customer?->name ?? 'Unknown',
                    'bookings' => $group->count(),
                    'revenue' => round($group->sum(fn (Booking $b) => (float) ($b->final_price ?? $b->quoted_price ?? 0)), 2),
                    'last_booking' => $lastAt?->format('d M Y'),
                    'repeat' => $group->count() >= 2,
                ];
            })
            ->sortByDesc('bookings')
            ->values();
    }

    /**
     * Resolve which corporate account (if any) a booking belongs to, trying every
     * signal in turn: the booking's own tag, the traveller's customer tag, then a
     * fuzzy match of the booker name / traveller name / email domain against the
     * business's names, codes, slugs and named contacts. Returns the account id or
     * null for a genuinely private customer.
     *
     * @param  array<string, int>  $map  normalised identifier => account id
     */
    public function accountIdForBooking(Booking $b, array $map): ?int
    {
        if ($b->corporate_account_id) {
            return (int) $b->corporate_account_id;
        }
        if ($b->customer?->corporate_account_id) {
            return (int) $b->customer->corporate_account_id;
        }

        // Fuzzy: does any business identifier appear in the booker, the traveller
        // name, or the email? (≥4 chars, so a short code can't false-match.)
        $haystacks = array_map(
            fn ($s) => $this->normaliseName($s),
            [$this->bookerText($b), $b->customer?->name, $b->customer?->email]
        );

        foreach ($map as $key => $id) {
            if (strlen($key) < 4) {
                continue;
            }
            foreach ($haystacks as $h) {
                if ($h !== '' && str_contains($h, $key)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * A lookup of every corporate business identifier — its name, slug, account
     * code, and each named contact — normalised to a bare lower-case key, mapping
     * to the account id. Cached briefly (the report is read far more than the
     * accounts change), so grouping a page of bookings stays cheap.
     *
     * @return array<string, int>
     */
    public function corporateNameMap(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('corporate_name_map', 300, function () {
            // Free web-mail domains never identify a business — a contact on gmail
            // must not roll every gmail traveller into that account.
            $freeMail = ['gmailcom', 'googlemailcom', 'hotmailcom', 'hotmailcouk', 'outlookcom', 'yahoocom', 'yahoocouk', 'icloudcom', 'livecom', 'livecouk', 'aolcom', 'msncom', 'mecom', 'btinternetcom', 'skycom', 'protonmailcom'];
            $domainKey = function (?string $email) use ($freeMail) {
                $email = strtolower(trim((string) $email));
                $at = strrpos($email, '@');
                if ($at === false) {
                    return null;
                }
                $domain = $this->normaliseName(substr($email, $at + 1));

                return ($domain !== '' && strlen($domain) >= 4 && ! in_array($domain, $freeMail, true)) ? $domain : null;
            };

            $map = [];
            foreach (\App\Models\CorporateAccount::with('contacts')->get() as $account) {
                foreach ([$account->name, $account->slug, $account->account_code] as $identifier) {
                    $key = $this->normaliseName($identifier);
                    if ($key !== '') {
                        $map[$key] = $account->id;
                    }
                }
                // The business email DOMAIN rolls in everyone who books off it —
                // so once JELD-WEN has one @jeld-wen contact, all their people match.
                if ($d = $domainKey($account->billing_email)) {
                    $map[$d] = $account->id;
                }
                foreach ($account->contacts as $contact) {
                    foreach ([$this->normaliseName($contact->name), $this->normaliseName($contact->email)] as $key) {
                        if ($key !== '') {
                            $map[$key] = $account->id;
                        }
                    }
                    if ($d = $domainKey($contact->email)) {
                        $map[$d] = $account->id;
                    }
                }
            }

            return $map;
        });
    }

    /** The booker text on a booking (who placed it), across the import/paste sources. */
    private function bookerText(Booking $b): ?string
    {
        foreach (['booked_by', 'booker_name', 'eto_customer'] as $key) {
            $value = trim((string) ($b->meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Lower-case, alphanumeric-only form of a name for fuzzy matching. */
    private function normaliseName(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $value)));
    }

    /** Non-cancelled jobs in the period, INCLUDING future ones (for payment split). */
    private function scheduled(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Booking::query()
            ->where(function (Builder $q) {
                $q->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                    // Charged cancellations still count toward money on the books.
                    ->orWhereNotNull('meta->cancellation->fee');
            })
            ->whereBetween('pickup_at', [$start, $end]);
    }

    /**
     * Profit view for the period: turnover (fares of jobs that ran), the driver
     * cost (cash the drivers kept on cash jobs + pay the business hands out on
     * card/account jobs), and the margin left — broken down per driver and per
     * corporate account. fare − driverCost lands on the business's real take
     * (a cash job nets just the deposit the business kept).
     *
     * @return array{jobs:int, revenue:float, driver_cost:float, profit:float, margin_pct:int, cash_to_drivers:float, per_driver:Collection, per_account:Collection}
     */
    public function profit(CarbonInterface $start, CarbonInterface $end): array
    {
        $jobs = $this->completed($start, $end)->with(['driver', 'corporateAccount'])->get();

        $breakdown = function (Collection $group, string $name): array {
            $fares = round($group->sum(fn (Booking $b) => $b->fareAmount() ?? 0), 2);
            $cost = round($group->sum(fn (Booking $b) => $b->driverCost()), 2);

            return ['name' => $name, 'jobs' => $group->count(), 'fares' => $fares, 'cost' => $cost, 'profit' => round($fares - $cost, 2)];
        };

        $revenue = round($jobs->sum(fn (Booking $b) => $b->fareAmount() ?? 0), 2);
        $driverCost = round($jobs->sum(fn (Booking $b) => $b->driverCost()), 2);
        $commission = round($revenue - $driverCost, 2); // the margin the business makes
        $adSpend = round((float) \App\Models\AdMetric::whereBetween('date', [$start->toDateString(), $end->toDateString()])->sum('spend'), 2);
        $netProfit = round($commission - $adSpend, 2);

        return [
            'jobs' => $jobs->count(),
            'revenue' => $revenue,
            'driver_cost' => $driverCost,
            'commission' => $commission,
            'ad_spend' => $adSpend,
            'net_profit' => $netProfit,
            'margin_pct' => $revenue > 0 ? (int) round($commission / $revenue * 100) : 0,
            'net_margin_pct' => $revenue > 0 ? (int) round($netProfit / $revenue * 100) : 0,
            'cash_to_drivers' => round($jobs->filter(fn (Booking $b) => $b->driverSettledByCustomer())
                ->sum(fn (Booking $b) => $b->cashDueToDriver() ?? 0), 2),
            'per_driver' => $jobs->groupBy(fn (Booking $b) => $b->payrollDriverName())
                ->map($breakdown)->sortByDesc('profit')->values(),
            'per_account' => $jobs->filter(fn (Booking $b) => $b->corporateAccount)
                ->groupBy(fn (Booking $b) => $b->corporateAccount->name)
                ->map($breakdown)->sortByDesc('fares')->values(),
        ];
    }

    /**
     * Income from bookings that CAME THROUGH in the period — keyed on when the
     * booking was created, not when the job runs. This is what to compare against
     * Google Ads spend for the same window: the ads generated the bookings that
     * came in that month, whatever date the pickup lands on. Cancelled/no-show
     * excluded (no income).
     *
     * @return array{revenue: float, jobs: int, average_fare: float}
     */
    public function createdSummary(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = Booking::query()
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('created_at', [$start, $end]);

        $jobs = (clone $base)->count();
        $revenue = (float) (clone $base)->sum(DB::raw(self::REVENUE));

        return [
            'revenue' => round($revenue, 2),
            'jobs' => $jobs,
            'average_fare' => $jobs ? round($revenue / $jobs, 2) : 0.0,
        ];
    }

    /** @return array{revenue: float, jobs: int, average_fare: float} */
    public function summary(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = $this->completed($start, $end);
        $jobs = (clone $base)->count();
        $revenue = (float) (clone $base)->sum(DB::raw(self::REVENUE));

        return [
            'revenue' => round($revenue, 2),
            'jobs' => $jobs,
            'average_fare' => $jobs ? round($revenue / $jobs, 2) : 0.0,
        ];
    }

    /**
     * Reserved (booked) figures: every non-cancelled job in the period INCLUDING
     * upcoming ones — the full value of business on the books, vs summary()'s
     * earned figure which only counts jobs that have already run.
     *
     * @return array{revenue: float, jobs: int}
     */
    public function reservedSummary(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = $this->scheduled($start, $end);

        return [
            'revenue' => round((float) (clone $base)->sum(DB::raw(self::REVENUE)), 2),
            'jobs' => (clone $base)->count(),
        ];
    }

    /** Earnings and job count per driver. */
    public function earningsByDriver(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return $this->completed($start, $end)
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('driver_id')
            ->with('driver:id,name')
            ->orderByDesc('revenue')
            ->get();
    }

    /** Bookings and revenue per vehicle type. */
    public function byVehicleType(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return $this->completed($start, $end)
            ->selectRaw('vehicle_type_id, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('vehicle_type_id')
            ->with('vehicleType:id,name')
            ->orderByDesc('jobs')
            ->get();
    }

    /** Top customers by revenue. */
    public function topCustomers(CarbonInterface $start, CarbonInterface $end, int $limit = 10): Collection
    {
        return $this->completed($start, $end)
            ->selectRaw('customer_id, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('customer_id')
            ->with('customer:id,name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    /**
     * Top customers for the Review page, but with corporate customers ROLLED UP
     * under their business: every JELD-WEN / LB Foster passenger counts toward the
     * one business line, while private customers stay as themselves. Ranked by
     * revenue. Each row carries a type ('business'|'customer'), an id and a
     * clickable url — a business opens its rebooking breakdown, a private customer
     * their booking history.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topEntities(CarbonInterface $start, CarbonInterface $end, int $limit = 12): Collection
    {
        return $this->entities($start, $end)->take($limit)->values();
    }

    /**
     * EVERY customer/business active in the period (businesses rolled up), ranked
     * by revenue, each row carrying a 'repeat' flag (2+ bookings in the window).
     * topEntities takes the head of this for the leaderboard; the Review page uses
     * the whole list to count and list repeat customers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entities(CarbonInterface $start, CarbonInterface $end): Collection
    {
        $map = $this->corporateNameMap();
        $accounts = \App\Models\CorporateAccount::get(['id', 'name'])->keyBy('id');

        $jobs = $this->completed($start, $end)
            ->with(['customer:id,name,email,phone,corporate_account_id'])
            ->get(['id', 'customer_id', 'corporate_account_id', 'final_price', 'quoted_price', 'meta']);

        $rev = fn (Collection $g) => round($g->sum(fn (Booking $b) => (float) ($b->final_price ?? $b->quoted_price ?? 0)), 2);

        // Split corporate bookings (rolled under their business) from private ones.
        $corporate = [];
        $individual = collect();
        foreach ($jobs as $b) {
            $acct = $this->accountIdForBooking($b, $map);
            if ($acct) {
                ($corporate[$acct] ??= collect())->push($b);
            } else {
                $individual->push($b);
            }
        }

        $entities = collect();

        foreach ($corporate as $accountId => $group) {
            $entities->push([
                'type' => 'business',
                'id' => $accountId,
                'name' => $accounts->get($accountId)?->name ?? 'Business',
                'jobs' => $group->count(),
                'revenue' => $rev($group),
                'customers' => $group->pluck('customer_id')->filter()->unique()->count(),
                'repeat' => $group->count() >= 2,
            ]);
        }

        // Merge the SAME private person booked under name variants or separate
        // records (matched by shared email, phone, or title-stripped name).
        foreach ($this->mergeSamePerson($individual) as $person) {
            $entities->push([
                'type' => 'customer',
                'id' => $person['id'],
                'name' => $person['name'],
                'jobs' => $person['jobs']->count(),
                'revenue' => $rev($person['jobs']),
                'customers' => 1,
                'merged' => $person['records'], // >1 when duplicate records were joined
                'repeat' => $person['jobs']->count() >= 2,
            ]);
        }

        return $entities->sortByDesc('revenue')->values();
    }

    /**
     * Group individual bookings so the SAME person counts once, even when they were
     * booked under name variants or separate customer records — e.g. "Karl Walton"
     * and "Dr Karl Walton", or "Richard" and "Richard Mauer" sharing a phone/email.
     * Union-find over three signals: normalised email, phone (international digits),
     * and title-stripped name. Bookings with no customer stay separate.
     *
     * @return array<int, array{id:int, name:string, records:int, jobs:Collection}>
     */
    private function mergeSamePerson(Collection $jobs): array
    {
        // One signal set per customer record.
        $sig = [];
        foreach ($jobs as $b) {
            $cid = (int) ($b->customer_id ?? 0);
            if ($cid === 0 || isset($sig[$cid])) {
                continue;
            }
            $c = $b->customer;
            $sig[$cid] = [
                'email' => $this->normaliseEmail($c?->email),
                'phone' => (string) \App\Support\Phone::wa($c?->phone),
                'name' => $this->normalisePersonName($c?->name),
                'display' => trim((string) ($c?->name)) ?: 'Unknown',
            ];
        }

        // Union-find: any shared signal joins two records into one person.
        $parent = [];
        foreach (array_keys($sig) as $cid) {
            $parent[$cid] = $cid;
        }
        $find = function ($x) use (&$parent, &$find) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $seen = ['email' => [], 'phone' => [], 'name' => []];
        foreach ($sig as $cid => $s) {
            foreach (['email', 'phone', 'name'] as $k) {
                $v = $s[$k];
                if ($v === '') {
                    continue;
                }
                if (isset($seen[$k][$v])) {
                    $parent[$find($cid)] = $find($seen[$k][$v]);
                } else {
                    $seen[$k][$v] = $cid;
                }
            }
        }

        // Bucket customer records by their merged root.
        $roots = [];
        foreach (array_keys($sig) as $cid) {
            $roots[$find($cid)][] = $cid;
        }

        $out = [];
        foreach ($roots as $cids) {
            $groupJobs = $jobs->filter(fn (Booking $b) => in_array((int) ($b->customer_id ?? -1), $cids, true))->values();
            $out[] = [
                'id' => $cids[0],
                'name' => $this->bestDisplayName(array_map(fn ($cid) => $sig[$cid]['display'], $cids)),
                'records' => count($cids),
                'jobs' => $groupJobs,
            ];
        }

        // Bookings with no linked customer stand alone — never merged blindly.
        foreach ($jobs->filter(fn (Booking $b) => (int) ($b->customer_id ?? 0) === 0) as $b) {
            $out[] = ['id' => 0, 'name' => $b->customer?->name ?? 'Unknown', 'records' => 1, 'jobs' => collect([$b])];
        }

        return $out;
    }

    /** Lower-case, validated email (blank when not a real email). */
    private function normaliseEmail(?string $email): string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Title-stripped, alphanumeric name key (so "Dr Karl Walton" == "Karl Walton"). */
    private function normalisePersonName(?string $name): string
    {
        $n = strtolower(trim((string) $name));
        $n = (string) preg_replace('/^(dr|mr|mrs|ms|miss|prof|professor|sir|dame|lord|lady|rev|mx)\.?\s+/', '', $n);

        return (string) preg_replace('/[^a-z0-9]+/', '', $n);
    }

    /** Pick the clearest display name from a merged person's records. */
    private function bestDisplayName(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));
        if ($names === []) {
            return 'Unknown';
        }
        $counts = array_count_values($names);
        usort($names, fn ($a, $b) => [$counts[$b], strlen($b)] <=> [$counts[$a], strlen($a)]);

        // Drop a leading title for the label (keep the fuller name otherwise).
        return trim((string) preg_replace('/^(Dr|Mr|Mrs|Ms|Miss|Prof|Professor|Sir|Dame|Lord|Lady|Rev|Mx)\.?\s+/i', '', $names[0]));
    }

    /** Top routes by volume (pickup → destination). */
    public function topRoutes(CarbonInterface $start, CarbonInterface $end, int $limit = 10): Collection
    {
        return $this->completed($start, $end)
            ->selectRaw('pickup_address, destination_address, COUNT(*) as jobs, SUM('.self::REVENUE.') as revenue')
            ->groupBy('pickup_address', 'destination_address')
            ->orderByDesc('jobs')
            ->limit($limit)
            ->get();
    }

    /**
     * Revenue and job count per calendar month across the period, in date order.
     * Grouped in PHP so it's portable across MySQL and SQLite.
     */
    public function monthlyRevenue(CarbonInterface $start, CarbonInterface $end): Collection
    {
        $now = now();

        // Pull ALL non-cancelled jobs (incl. upcoming) so each month can show both
        // earned (run) and reserved (booked) figures.
        return $this->scheduled($start, $end)
            ->selectRaw('pickup_at, '.self::REVENUE.' as revenue')
            ->get()
            ->groupBy(fn ($b) => $b->pickup_at->format('Y-m'))
            ->map(function ($group, $ym) use ($now) {
                $earned = $group->filter(fn ($b) => $b->pickup_at->lte($now));

                return [
                    'month' => $ym,
                    'label' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('M Y'),
                    'jobs' => $earned->count(),
                    'revenue' => round((float) $earned->sum('revenue'), 2),
                    'booked_jobs' => $group->count(),
                    'booked_revenue' => round((float) $group->sum('revenue'), 2),
                ];
            })
            ->sortKeys()
            ->values();
    }

    /**
     * A transparency breakdown of what's actually being counted in the period, so
     * the headline job/revenue figures can be sanity-checked: how many counted
     * jobs, how many are return legs (a return is two legs), how many have no
     * fare, how many share a booking reference (possible duplicates), and how
     * many were excluded as cancelled/no-show.
     *
     * @return array<string, int>
     */
    public function dataHealth(CarbonInterface $start, CarbonInterface $end): array
    {
        $base = $this->completed($start, $end);

        $noPrice = (clone $base)
            ->where(fn ($q) => $q->whereNull('final_price')->orWhere('final_price', '<=', 0))
            ->where(fn ($q) => $q->whereNull('quoted_price')->orWhere('quoted_price', '<=', 0))
            ->count();

        // Booking references that appear more than once in the window.
        $dupeRefs = Booking::query()
            ->whereBetween('pickup_at', [$start, $end])
            ->whereNotNull('external_reference')
            ->where('external_reference', '!=', '')
            ->groupBy('external_reference')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('external_reference');

        return [
            'jobs' => (clone $base)->count(),
            'return_legs' => (clone $base)->where('is_return_leg', true)->count(),
            'no_price' => $noPrice,
            'duplicate_refs' => $dupeRefs->count(),
            'excluded' => Booking::whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
                ->whereBetween('pickup_at', [$start, $end])->count(),
        ];
    }

    /** Cancellations/no-shows in the period, and the cancellation rate. */
    public function cancellations(CarbonInterface $start, CarbonInterface $end): array
    {
        $cancelled = Booking::query()
            ->whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->whereBetween('pickup_at', [$start, $end])
            ->count();
        $ran = $this->completed($start, $end)->count();
        $total = $cancelled + $ran;

        return [
            'cancelled' => $cancelled,
            'total' => $total,
            'rate_pct' => $total ? round(($cancelled / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Money collected vs genuinely still owed for the period.
     *
     * A job counts as COLLECTED if it's marked paid OR its pickup has already
     * passed — because a job that has run has been paid (card up front, cash
     * taken by the driver on the day). "Pending" only means money still to come
     * on a FUTURE job (a cash fare the driver will collect on the pickup day), so
     * OUTSTANDING is limited to not-yet-run, unpaid jobs.
     *
     * @return array{collected: float, outstanding: float}
     */
    public function paymentSplit(CarbonInterface $start, CarbonInterface $end): array
    {
        $now = now();
        $base = $this->scheduled($start, $end); // include future jobs here

        $collected = (float) (clone $base)
            ->where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('pickup_at', '<', $now))
            ->sum(DB::raw(self::REVENUE));

        // Unpaid AND still in the future = a cash fare yet to be collected.
        $outstanding = (float) (clone $base)
            ->where('payment_status', '!=', 'paid')
            ->where('pickup_at', '>=', $now)
            ->sum(DB::raw(self::REVENUE));

        return ['collected' => round($collected, 2), 'outstanding' => round($outstanding, 2)];
    }

    /** Compare a period against the immediately preceding equal-length period. */
    public function comparison(CarbonInterface $start, CarbonInterface $end): array
    {
        $length = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($length - 1)->startOfDay();

        $current = $this->summary($start, $end);
        $previous = $this->summary($prevStart, $prevEnd);

        return [
            'current' => $current,
            'previous' => $previous,
            'revenue_change_pct' => $this->pctChange($previous['revenue'], $current['revenue']),
            'jobs_change_pct' => $this->pctChange($previous['jobs'], $current['jobs']),
        ];
    }

    private function pctChange(float $from, float $to): ?float
    {
        if ($from == 0.0) {
            return $to > 0 ? 100.0 : null;
        }

        return round((($to - $from) / $from) * 100, 1);
    }
}
