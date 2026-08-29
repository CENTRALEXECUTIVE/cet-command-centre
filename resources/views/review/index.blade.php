@extends('layouts.app')
@section('title', 'Review')

@section('content')
    <h1 class="page-title">Business Review</h1>
    <p class="page-sub">{{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif


    @php
        $presets = ['last30' => 'Last 30 days', 'last90' => 'Last 90 days', 'this_year' => 'This year', 'last_month' => 'Last month', 'all' => 'All time'];
        $active = $activePreset ?? 'last30';
    @endphp
    <div class="toolbar" style="gap:8px;flex-wrap:wrap;margin-bottom:10px">
        @foreach($presets as $key => $label)
            <a href="{{ route('review.index', ['preset' => $key]) }}"
               class="btn {{ $active === $key ? 'btn-dark' : 'btn-light' }}"
               style="padding:8px 14px">{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('review.index') }}" class="toolbar" style="flex-wrap:wrap">
        <span class="muted" style="align-self:center">Custom range:</span>
        <input type="date" name="start" value="{{ $start->toDateString() }}" style="width:auto">
        <input type="date" name="end" value="{{ $end->toDateString() }}" style="width:auto">
        <button class="btn {{ $active === 'custom' ? 'btn-dark' : 'btn-light' }}" type="submit" style="padding:9px 16px">Apply</button>
    </form>

    @php
        $c = $comparison['current'];
        // Every stat below links to the exact list behind it. Base window params
        // reused for each drill-through (dates come from the chosen period).
        $win = ['from' => $start->toDateString(), 'to' => $end->toDateString()];
        $lnkMade      = route('bookings.index', $win + ['by' => 'created']);
        $lnkCompleted = route('bookings.index', $win + ['by' => 'pickup', 'ran' => 1]);
        $lnkBooked    = route('bookings.index', $win + ['by' => 'pickup']);
        $lnkPaid      = route('bookings.index', $win + ['by' => 'pickup', 'payment' => 'paid']);
        $lnkOwing     = route('bookings.index', $win + ['by' => 'pickup', 'payment' => 'unpaid']);
        $lnkCancelled = route('bookings.index', $win + ['by' => 'pickup', 'status' => 'cancelled']);
    @endphp
    <p class="muted" style="font-size:13px;margin:0 0 8px">Every figure below is clickable — tap it to see the exact bookings behind it. <strong>Came in</strong> = bookings placed in these dates (ETO's “Created” date), whatever day the trip runs. <strong>Completed</strong> = trips already done (money taken). <strong>Total trips</strong> = everything on the books by trip date, including trips still to come.</p>

    {{-- The two headline figures the office watches: what came IN this period vs
         the total ON THE BOOKS for this period. They measure different things and
         rarely match — the note spells out why, so 14k next to 16k never reads as
         a bug. --}}
    <div class="card" style="border-left:4px solid var(--gold);margin-bottom:16px">
        <div class="grid grid-2" style="gap:16px">
            <a href="{{ $lnkMade }}" class="stat stat-link" style="text-align:left">
                <div class="n">£{{ number_format($created['revenue'] ?? 0, 2) }} <span style="font-size:15px;font-weight:500;color:var(--muted,#888)">· {{ $created['jobs'] ?? 0 }} {{ \Illuminate\Support\Str::plural('booking', $created['jobs'] ?? 0) }}</span></div>
                <div class="l">🆕 Came in this period — new bookings placed (counted by the day they were booked)</div>
            </a>
            <a href="{{ $lnkBooked }}" class="stat stat-link" style="text-align:left">
                <div class="n">£{{ number_format($reserved['revenue'] ?? 0, 2) }} <span style="font-size:15px;font-weight:500;color:var(--muted,#888)">· {{ $reserved['jobs'] ?? 0 }} {{ \Illuminate\Support\Str::plural('trip', $reserved['jobs'] ?? 0) }}</span></div>
                <div class="l">📖 Total trips for this period — everything on the books (counted by trip date, incl. upcoming)</div>
            </a>
        </div>
        <p class="hint" style="margin:10px 0 0">These two differ on purpose: <strong>came in</strong> counts a booking on the day it was <em>placed</em>; <strong>total trips</strong> counts it on the day the <em>trip runs</em>. A trip booked last month but running this month lands in the total, not in came-in — so the two rarely match. It isn't an error.</p>
    </div>

    {{-- Bookings MADE in the period (by created date), not by trip date — "how
         many came in this month" regardless of when the pickup lands. --}}
    <div class="grid grid-3" style="margin-bottom:14px">
        <a href="{{ $lnkMade }}" class="stat stat-link" style="border-color:var(--gold);border-width:2px">
            <div class="n">{{ $created['jobs'] ?? 0 }}</div>
            <div class="l">🆕 Bookings made this period (came in)</div>
        </a>
        <a href="{{ $lnkMade }}" class="stat stat-link">
            <div class="n">£{{ number_format($created['revenue'] ?? 0, 2) }}</div>
            <div class="l">Value of bookings made</div>
        </a>
        <a href="{{ $lnkMade }}" class="stat stat-link"><div class="n">£{{ number_format($created['average_fare'] ?? 0, 2) }}</div><div class="l">Average new-booking fare</div></a>
    </div>
    <div class="grid grid-3" style="margin-bottom:14px">
        <a href="{{ $lnkCompleted }}" class="stat stat-link">
            <div class="n">£{{ number_format($c['revenue'], 2) }}</div>
            <div class="l">💷 Completed — money taken so far
                @if(!is_null($comparison['revenue_change_pct']))
                    <span style="color:{{ $comparison['revenue_change_pct'] >= 0 ? '#1f7a44' : '#b32020' }}">
                        {{ $comparison['revenue_change_pct'] >= 0 ? '▲' : '▼' }} {{ abs($comparison['revenue_change_pct']) }}%</span>
                @endif
            </div>
        </a>
        <a href="{{ $lnkCompleted }}" class="stat stat-link"><div class="n">{{ $c['jobs'] }}</div><div class="l">Trips completed</div></a>
        <a href="{{ $lnkCompleted }}" class="stat stat-link"><div class="n">£{{ number_format($c['average_fare'], 2) }}</div><div class="l">Average fare</div></a>
    </div>
    <div class="grid grid-3" style="margin-bottom:24px">
        <a href="{{ $lnkBooked }}" class="stat stat-link" style="border-color:var(--gold)">
            <div class="n">£{{ number_format($reserved['revenue'] ?? 0, 2) }}</div>
            <div class="l">📖 Total trips (by trip date, this range)</div>
        </a>
        <a href="{{ $lnkBooked }}" class="stat stat-link"><div class="n">{{ $reserved['jobs'] ?? 0 }}</div><div class="l">Total trips booked</div></a>
        <a href="{{ $lnkBooked }}" class="stat stat-link">
            <div class="n" style="color:#b8860b">£{{ number_format(max(0, ($reserved['revenue'] ?? 0) - $c['revenue']), 2) }}</div>
            <div class="l">Still to come (upcoming trips)</div>
        </a>
    </div>

    <div class="grid grid-3" style="margin-bottom:24px">
        <a href="{{ $lnkPaid }}" class="stat stat-link">
            <div class="n" style="color:#1f7a44">£{{ number_format($payments['collected'], 2) }}</div>
            <div class="l">Collected (paid)</div>
        </a>
        <a href="{{ $lnkOwing }}" class="stat stat-link">
            <div class="n">£{{ number_format($payments['outstanding'], 2) }}</div>
            <div class="l">Cash to collect — upcoming jobs (driver collects on the day)</div>
        </a>
        <a href="{{ $lnkCancelled }}" class="stat stat-link">
            <div class="n">{{ $cancellations['cancelled'] }} <span style="font-size:16px;font-weight:400">({{ $cancellations['rate_pct'] }}%)</span></div>
            <div class="l">Cancellations / no-shows</div>
        </a>
    </div>

    {{-- AI / business review --}}
    <div class="card" style="border-left:4px solid #FBBA2A">
        <h2>📋 Review &amp; recommendations
            <span class="page-sub" style="font-weight:400">
                {{ $insights['source'] === 'ai' ? '· AI analysis (claude-opus-4-8)' : '· summary' }}</span>
        </h2>
        <p style="font-size:15px">{{ $insights['summary'] }}</p>

        <div class="grid grid-2" style="margin-top:8px">
            <div>
                <h3>What we can improve</h3>
                <ul>@forelse($insights['improve'] as $i)<li>{{ $i }}</li>@empty<li>—</li>@endforelse</ul>
            </div>
            <div>
                <h3>Next steps</h3>
                <ul>@forelse($insights['next_steps'] as $i)<li>{{ $i }}</li>@empty<li>—</li>@endforelse</ul>
            </div>
        </div>
    </div>

    {{-- Monthly revenue trend --}}
    <div class="card">
        <h2>Monthly revenue <span class="muted" style="font-weight:400;font-size:13px">— each full calendar month by trip date (not the range above): bold = completed money, “booked” = total incl. upcoming trips</span></h2>
        @php $maxRev = max(1, (float) ($monthly->max('booked_revenue') ?? $monthly->max('revenue') ?? 1)); @endphp
        @forelse($monthly as $m)
            @php $hasUpcoming = ($m['booked_revenue'] ?? $m['revenue']) > $m['revenue'] + 0.01; @endphp
            <div style="display:flex;align-items:center;gap:12px;margin:6px 0">
                <a href="{{ route('bookings.index', ['month' => $m['month']]) }}" style="width:72px;flex:none;color:var(--muted,#666);font-size:13px" title="See {{ $m['label'] }} bookings">{{ $m['label'] }}</a>
                <span style="flex:1;background:rgba(128,128,128,.15);border-radius:5px;overflow:hidden;position:relative">
                    {{-- booked (lighter) behind, earned (gold) in front --}}
                    <span style="position:absolute;inset:0;height:20px;border-radius:5px;background:rgba(251,186,42,.35);width:{{ max(1, round(($m['booked_revenue'] ?? $m['revenue']) / $maxRev * 100)) }}%"></span>
                    <span style="display:block;height:20px;border-radius:5px;background:#FBBA2A;width:{{ max(1, round($m['revenue'] / $maxRev * 100)) }}%;position:relative"></span>
                </span>
                <span style="width:190px;flex:none;text-align:right;font-variant-numeric:tabular-nums">
                    <strong>£{{ number_format($m['revenue'], 0) }}</strong>
                    <span style="color:var(--muted,#888)">· {{ $m['jobs'] }} done</span>
                    @if($hasUpcoming)
                        <span style="display:block;font-size:12px;color:#b8860b">£{{ number_format($m['booked_revenue'], 0) }} booked · {{ $m['booked_jobs'] }} trips</span>
                    @endif
                </span>
            </div>
        @empty
            <p class="muted mb-0">No jobs in this period.</p>
        @endforelse
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Revenue by vehicle type</h2>
            <table class="table">
                <thead><tr><th>Vehicle</th><th>Jobs</th><th>Revenue</th></tr></thead>
                <tbody>
                @forelse($byVehicle as $v)
                    @php $vurl = route('bookings.index', $win + ['by' => 'pickup', 'ran' => 1, 'vehicle' => $v->vehicle_type_id]); @endphp
                    <tr style="cursor:pointer" onclick="window.location='{{ $vurl }}'">
                        <td><a href="{{ $vurl }}">{{ $v->vehicleType->name ?? '—' }}</a></td><td>{{ $v->jobs }}</td>
                        <td>£{{ number_format($v->revenue, 2) }}</td></tr>
                @empty
                    <tr><td colspan="3">No completed jobs in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            @php
                $repeat = collect($repeatCustomers ?? []);
                $repeatCount = $repeat->count();
                $repeatBookings = (int) $repeat->sum('jobs');
            @endphp
            <h2>Top customers &amp; businesses</h2>
            <p class="muted" style="font-size:12px;margin:0 0 8px">Corporate passengers are grouped under their business — tap a business to see how often each of their people has re-booked.</p>

            {{-- Repeat-customer headline: how many customers/businesses booked more
                 than once in this period, and the full list to see who they are. --}}
            <div class="stat" style="text-align:left;border-color:var(--gold);margin-bottom:10px">
                <div class="n">🔁 {{ $repeatCount }}</div>
                <div class="l">Repeat {{ \Illuminate\Support\Str::plural('customer', $repeatCount) }} (2+ bookings this period) · {{ $repeatBookings }} {{ \Illuminate\Support\Str::plural('booking', $repeatBookings) }} between them</div>
            </div>

            <table class="table">
                <thead><tr><th>Customer / business</th><th>Jobs</th><th>Revenue</th></tr></thead>
                <tbody>
                @forelse($topCustomers as $x)
                    @php
                        $curl = $x['type'] === 'business'
                            ? route('reports.business', $x['id'])
                            : route('bookings.index', $win + ['by' => 'pickup', 'q' => $x['name']]);
                    @endphp
                    <tr style="cursor:pointer" onclick="window.location='{{ $curl }}'">
                        <td>
                            <a href="{{ $curl }}">{{ $x['name'] }}</a>
                            @if($x['type'] === 'business')<span class="badge" style="background:#5b2bc7;color:#fff;font-size:10px;vertical-align:middle;margin-left:4px">🏢 {{ $x['customers'] }} {{ \Illuminate\Support\Str::plural('customer', $x['customers']) }}</span>@endif
                            @if(!empty($x['repeat']))<span class="badge" style="background:#1f7a44;color:#fff;font-size:10px;vertical-align:middle;margin-left:4px">🔁 repeat</span>@endif
                        </td>
                        <td>{{ $x['jobs'] }}</td>
                        <td>£{{ number_format($x['revenue'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No data.</td></tr>
                @endforelse
                </tbody>
            </table>

            {{-- The full repeat list, collapsed so it doesn't dominate the page. --}}
            @if($repeatCount > 0)
                <details style="margin-top:12px">
                    <summary style="cursor:pointer;font-weight:600;font-size:14px">See all {{ $repeatCount }} repeat {{ \Illuminate\Support\Str::plural('customer', $repeatCount) }}</summary>
                    <table class="table" style="margin-top:8px">
                        <thead><tr><th>Customer / business</th><th>Bookings</th><th>Revenue</th></tr></thead>
                        <tbody>
                        @foreach($repeat as $x)
                            @php
                                $rurl = $x['type'] === 'business'
                                    ? route('reports.business', $x['id'])
                                    : route('bookings.index', $win + ['by' => 'pickup', 'q' => $x['name']]);
                            @endphp
                            <tr style="cursor:pointer" onclick="window.location='{{ $rurl }}'">
                                <td>
                                    <a href="{{ $rurl }}">{{ $x['name'] }}</a>
                                    @if($x['type'] === 'business')<span class="badge" style="background:#5b2bc7;color:#fff;font-size:10px;vertical-align:middle;margin-left:4px">🏢 {{ $x['customers'] }} {{ \Illuminate\Support\Str::plural('customer', $x['customers']) }}</span>@endif
                                </td>
                                <td>{{ $x['jobs'] }}×</td>
                                <td>£{{ number_format($x['revenue'], 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Top routes</h2>
            <table class="table">
                <thead><tr><th>Route</th><th>Jobs</th><th>Revenue</th></tr></thead>
                <tbody>
                @forelse($topRoutes as $r)
                    <tr><td>{{ \Illuminate\Support\Str::limit($r->pickup_address, 24) }} →
                            {{ \Illuminate\Support\Str::limit($r->destination_address, 24) }}</td>
                        <td>{{ $r->jobs }}</td><td>£{{ number_format($r->revenue, 2) }}</td></tr>
                @empty
                    <tr><td colspan="3">No data.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Ad spend <a href="{{ route('marketing.ads') }}" style="font-size:13px;font-weight:400">full ads report →</a></h2>
            <div class="grid grid-2" style="gap:12px">
                <a href="{{ route('marketing.ads') }}" class="stat stat-link"><div class="n">£{{ number_format($ads['spend'], 2) }}</div><div class="l">Spend</div></a>
                <a href="{{ route('marketing.ads') }}" class="stat stat-link"><div class="n">{{ $ads['roas'] ? $ads['roas'].'×' : '—' }}</div><div class="l">ROAS (revenue ÷ spend)</div></a>
                <a href="{{ route('marketing.ads') }}" class="stat stat-link"><div class="n">{{ $ads['conversions'] }}</div><div class="l">Conversions</div></a>
                <a href="{{ route('marketing.ads') }}" class="stat stat-link"><div class="n">{{ $ads['cost_per_conversion'] ? '£'.$ads['cost_per_conversion'] : '—' }}</div><div class="l">Cost / conversion</div></a>
            </div>
        </div>
    </div>

    {{-- ─────────── Google Ads analysis ─────────── --}}
    @php $aa = $adsAnalysis ?? null; @endphp
    @if($aa)
        @php
            $m = $aa['metrics'];
            $rec = $aa['recommendation'];
            $verdictColour = match($rec['verdict']) {
                'Increase budget' => '#1f7a44',
                'Reduce/optimise' => '#b32020',
                default => '#b8860b',
            };
        @endphp
        <div class="card">
            <h2>Google Ads analysis</h2>

            {{-- Recommendation --}}
            <div style="border:1px solid {{ $verdictColour }}44;border-left:4px solid {{ $verdictColour }};border-radius:10px;padding:14px;margin-bottom:16px;background:rgba(0,0,0,.02)">
                <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
                    <strong style="font-size:16px;color:{{ $verdictColour }}">{{ $rec['verdict'] }}</strong>
                    <span class="badge" style="background:{{ $verdictColour }};color:#fff">Budget: {{ $rec['budget'] }}</span>
                </div>
                <p style="margin:8px 0 0">{{ $rec['summary'] }}</p>
                @if(!empty($rec['actions']))
                    <ul style="margin:8px 0 0;padding-left:18px">
                        @foreach($rec['actions'] as $a)<li style="margin:3px 0">{{ $a }}</li>@endforeach
                    </ul>
                @endif
                <p class="hint" style="margin:8px 0 0">{{ $rec['source'] === 'ai' ? 'AI analysis · '.config('cet.ai_model') : 'Rule-based (add an Anthropic key for AI analysis)' }}</p>
            </div>

            {{-- Headline figures table --}}
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Spend</th><th>Revenue</th><th>ROAS</th><th>Conv.</th><th>Cost/conv.</th><th>Clicks</th><th>CTR</th><th>Avg CPC</th></tr></thead>
                    <tbody><tr>
                        <td>£{{ number_format($m['spend'], 2) }}</td>
                        <td>£{{ number_format($m['revenue'], 2) }}</td>
                        <td>{{ $m['roas'] ? $m['roas'].'×' : '—' }}</td>
                        <td>{{ $m['conversions'] }}</td>
                        <td>{{ $m['cost_per_conversion'] ? '£'.$m['cost_per_conversion'] : '—' }}</td>
                        <td>{{ number_format($m['clicks']) }}</td>
                        <td>{{ $m['ctr'] !== null ? $m['ctr'].'%' : '—' }}</td>
                        <td>{{ $m['avg_cpc'] ? '£'.$m['avg_cpc'] : '—' }}</td>
                    </tr></tbody>
                </table>
            </div>

            {{-- Per campaign --}}
            @if(!empty($aa['byCampaign']))
                <h3 style="margin:18px 0 6px;font-size:14px">By campaign</h3>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Campaign</th><th>Spend</th><th>Conv.</th><th>CPA</th><th>CTR</th><th>CPC</th></tr></thead>
                        <tbody>
                            @foreach($aa['byCampaign'] as $c)
                                <tr>
                                    <td>{{ $c['campaign'] }}</td>
                                    <td>£{{ number_format($c['spend'], 2) }}</td>
                                    <td>{{ $c['conversions'] }}</td>
                                    <td>{{ $c['cpa'] ? '£'.$c['cpa'] : '—' }}</td>
                                    <td>{{ $c['ctr'] !== null ? $c['ctr'].'%' : '—' }}</td>
                                    <td>{{ $c['cpc'] ? '£'.$c['cpc'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Top keywords --}}
            @if(!empty($aa['topKeywords']))
                <h3 style="margin:18px 0 6px;font-size:14px">Top keywords by spend</h3>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Keyword</th><th>Cost</th><th>Clicks</th><th>Conv.</th><th>CPA</th><th>CTR</th></tr></thead>
                        <tbody>
                            @foreach($aa['topKeywords'] as $k)
                                <tr>
                                    <td>{{ $k['keyword'] }}</td>
                                    <td>£{{ number_format($k['cost'], 2) }}</td>
                                    <td>{{ $k['clicks'] }}</td>
                                    <td>{{ $k['conversions'] }}</td>
                                    <td style="{{ $k['conversions'] == 0 && $k['cost'] > 0 ? 'color:#b32020' : '' }}">{{ $k['cpa'] ? '£'.$k['cpa'] : ($k['cost'] > 0 ? 'no conv.' : '—') }}</td>
                                    <td>{{ $k['ctr'] !== null ? $k['ctr'].'%' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="hint" style="margin-top:8px">Keywords in <span style="color:#b32020">red</span> spent money with no bookings — candidates to pause or add as negatives.</p>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>Booking pipeline (this period)</h2>
        <div class="toolbar" style="flex-wrap:wrap">
            @forelse($pipeline as $status => $count)
                <span class="badge">{{ ucfirst(str_replace('_', ' ', $status)) }}: <strong>{{ $count }}</strong></span>
            @empty
                <span>No bookings in this period.</span>
            @endforelse
        </div>
    </div>

    {{-- Data & tools — tucked away; auto-opens only if something needs attention --}}
    @if(!empty($dataHealth))
        @php $needsAttention = ($dataHealth['no_price'] ?? 0) > 0 || ($dataHealth['duplicate_refs'] ?? 0) > 0; @endphp
        <details class="card" {{ $needsAttention ? 'open' : '' }} style="margin-top:16px">
            <summary style="cursor:pointer;font-weight:700;font-size:15px">🔧 Data &amp; tools @if($needsAttention)<span class="badge badge-pending" style="margin-left:6px">needs attention</span>@endif</summary>

            <div class="grid grid-3" style="gap:10px;margin-top:14px">
                <div class="stat"><div class="n">{{ number_format($dataHealth['jobs']) }}</div><div class="l">Completed jobs (not cancelled)</div></div>
                <div class="stat"><div class="n" style="{{ $dataHealth['no_price'] > 0 ? 'color:#b8860b' : '' }}">{{ number_format($dataHealth['no_price']) }}</div><div class="l">…with no fare (add £0)</div></div>
                <div class="stat"><div class="n" style="{{ $dataHealth['duplicate_refs'] > 0 ? 'color:#b32020' : '' }}">{{ number_format($dataHealth['duplicate_refs']) }}</div><div class="l">Duplicate references</div></div>
                <div class="stat"><div class="n">{{ number_format($dataHealth['return_legs']) }}</div><div class="l">Return legs (a return = 2 legs)</div></div>
                <div class="stat"><div class="n">{{ number_format($dataHealth['excluded']) }}</div><div class="l">Excluded (cancelled / no-show)</div></div>
            </div>

            <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">
                <form method="POST" action="{{ route('review.backfill-prices', request()->only('preset','start','end')) }}" style="display:inline">
                    @csrf
                    <button class="btn btn-primary" style="padding:6px 14px;font-size:13px">Fix missing prices</button>
                </form>
                <span class="hint" style="margin-left:8px">Recovers fares onto jobs that came in without one. Anything left blank → re-import the ETO export from <a href="{{ route('imports.index') }}">Imports</a>.</span>
                @if($dataHealth['duplicate_refs'] > 0)
                    <p class="hint" style="margin:10px 0 0;color:#b32020">{{ $dataHealth['duplicate_refs'] }} booking reference(s) appear more than once — likely duplicates. Tell me and I'll add a de-dupe tool.</p>
                @endif
            </div>
        </details>
    @endif
@endsection
