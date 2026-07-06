@extends('layouts.app')
@section('title', 'Review')

@section('content')
    <h1 class="page-title">Business Review</h1>
    <p class="page-sub">{{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    @if(!empty($dataHealth))
        <div class="card" style="margin-bottom:16px">
            <h2 style="margin:0 0 4px">Data check <span class="muted" style="font-weight:400;font-size:13px">— what's behind the {{ number_format($dataHealth['jobs']) }} counted jobs</span></h2>
            <div class="grid grid-3" style="gap:10px;margin-top:10px">
                <div class="stat"><div class="n">{{ number_format($dataHealth['jobs']) }}</div><div class="l">Counted jobs (ran, not cancelled)</div></div>
                <div class="stat"><div class="n" style="{{ $dataHealth['no_price'] > 0 ? 'color:#b8860b' : '' }}">{{ number_format($dataHealth['no_price']) }}</div><div class="l">…with no fare (add £0)</div></div>
                <div class="stat"><div class="n" style="{{ $dataHealth['duplicate_refs'] > 0 ? 'color:#b32020' : '' }}">{{ number_format($dataHealth['duplicate_refs']) }}</div><div class="l">Duplicate references</div></div>
                <div class="stat"><div class="n">{{ number_format($dataHealth['return_legs']) }}</div><div class="l">Return legs (a return = 2 legs)</div></div>
                <div class="stat"><div class="n">{{ number_format($dataHealth['excluded']) }}</div><div class="l">Excluded (cancelled / no-show)</div></div>
            </div>
            @if($dataHealth['duplicate_refs'] > 0)
                <p class="hint" style="margin:10px 0 0;color:#b32020">{{ $dataHealth['duplicate_refs'] }} booking reference(s) appear more than once — likely duplicates inflating the count. Tell me and I'll add a de-dupe tool.</p>
            @endif
            @if($dataHealth['no_price'] > 0)
                <p class="hint" style="margin:8px 0 0">{{ $dataHealth['no_price'] }} job(s) have no fare — use “Fix missing prices” below, then re-import the ETO export for anything still blank.</p>
            @endif
        </div>
    @endif

    <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.06);margin-bottom:16px">
        <strong>Figures look low?</strong>
        Some older jobs came in without a fare, so they count as jobs but add nothing to revenue.
        <form method="POST" action="{{ route('review.backfill-prices', request()->only('preset','start','end')) }}" style="display:inline;margin-left:6px">
            @csrf
            <button class="btn btn-primary" style="padding:6px 14px;font-size:13px">Fix missing prices</button>
        </form>
        <p class="hint" style="margin:8px 0 0">Recovers fares stored on each job. If some can't be recovered, it'll say how many — then import the ETO export for those months from <a href="{{ route('imports.index') }}">Imports</a>.</p>
    </div>

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

    @php $c = $comparison['current']; @endphp
    <div class="grid grid-3" style="margin-bottom:24px">
        <div class="stat">
            <div class="n">£{{ number_format($c['revenue'], 2) }}</div>
            <div class="l">Revenue
                @if(!is_null($comparison['revenue_change_pct']))
                    <span style="color:{{ $comparison['revenue_change_pct'] >= 0 ? '#1f7a44' : '#b32020' }}">
                        {{ $comparison['revenue_change_pct'] >= 0 ? '▲' : '▼' }} {{ abs($comparison['revenue_change_pct']) }}%</span>
                @endif
            </div>
        </div>
        <div class="stat"><div class="n">{{ $c['jobs'] }}</div><div class="l">Completed jobs</div></div>
        <div class="stat"><div class="n">£{{ number_format($c['average_fare'], 2) }}</div><div class="l">Average fare</div></div>
    </div>

    <div class="grid grid-3" style="margin-bottom:24px">
        <div class="stat">
            <div class="n" style="color:#1f7a44">£{{ number_format($payments['collected'], 2) }}</div>
            <div class="l">Collected (paid)</div>
        </div>
        <div class="stat">
            <div class="n">£{{ number_format($payments['outstanding'], 2) }}</div>
            <div class="l">Cash to collect — upcoming jobs (driver collects on the day)</div>
        </div>
        <div class="stat">
            <div class="n">{{ $cancellations['cancelled'] }} <span style="font-size:16px;font-weight:400">({{ $cancellations['rate_pct'] }}%)</span></div>
            <div class="l">Cancellations / no-shows</div>
        </div>
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
        <h2>Monthly revenue</h2>
        @php $maxRev = max(1, (float) ($monthly->max('revenue') ?? 1)); @endphp
        @forelse($monthly as $m)
            <div style="display:flex;align-items:center;gap:12px;margin:6px 0">
                <span style="width:72px;flex:none;color:var(--muted,#666);font-size:13px">{{ $m['label'] }}</span>
                <span style="flex:1;background:rgba(128,128,128,.15);border-radius:5px;overflow:hidden">
                    <span style="display:block;height:20px;border-radius:5px;background:#FBBA2A;width:{{ max(1, round($m['revenue'] / $maxRev * 100)) }}%"></span>
                </span>
                <span style="width:150px;flex:none;text-align:right;font-variant-numeric:tabular-nums">
                    <strong>£{{ number_format($m['revenue'], 0) }}</strong>
                    <span style="color:var(--muted,#888)">· {{ $m['jobs'] }} jobs</span>
                </span>
            </div>
        @empty
            <p class="muted mb-0">No completed jobs in this period.</p>
        @endforelse
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Revenue by vehicle type</h2>
            <table class="table">
                <thead><tr><th>Vehicle</th><th>Jobs</th><th>Revenue</th></tr></thead>
                <tbody>
                @forelse($byVehicle as $v)
                    <tr><td>{{ $v->vehicleType->name ?? '—' }}</td><td>{{ $v->jobs }}</td>
                        <td>£{{ number_format($v->revenue, 2) }}</td></tr>
                @empty
                    <tr><td colspan="3">No completed jobs in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Top customers</h2>
            <table class="table">
                <thead><tr><th>Customer</th><th>Jobs</th><th>Revenue</th></tr></thead>
                <tbody>
                @forelse($topCustomers as $x)
                    <tr><td>{{ $x->customer->name ?? 'Unknown' }}</td><td>{{ $x->jobs }}</td>
                        <td>£{{ number_format($x->revenue, 2) }}</td></tr>
                @empty
                    <tr><td colspan="3">No data.</td></tr>
                @endforelse
                </tbody>
            </table>
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
            <h2>Ad spend</h2>
            <div class="grid grid-2" style="gap:12px">
                <div class="stat"><div class="n">£{{ number_format($ads['spend'], 2) }}</div><div class="l">Spend</div></div>
                <div class="stat"><div class="n">{{ $ads['roas'] ? $ads['roas'].'×' : '—' }}</div><div class="l">ROAS (revenue ÷ spend)</div></div>
                <div class="stat"><div class="n">{{ $ads['conversions'] }}</div><div class="l">Conversions</div></div>
                <div class="stat"><div class="n">{{ $ads['cost_per_conversion'] ? '£'.$ads['cost_per_conversion'] : '—' }}</div><div class="l">Cost / conversion</div></div>
            </div>
        </div>
    </div>

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
@endsection
