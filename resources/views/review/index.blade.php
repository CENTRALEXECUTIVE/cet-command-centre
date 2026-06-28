@extends('layouts.app')
@section('title', 'Review')

@section('content')
    <h1 class="page-title">Business Review</h1>
    <p class="page-sub">{{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</p>

    <form method="GET" action="{{ route('review.index') }}" class="toolbar">
        <input type="date" name="start" value="{{ $start->toDateString() }}" style="width:auto">
        <input type="date" name="end" value="{{ $end->toDateString() }}" style="width:auto">
        <button class="btn btn-dark" type="submit" style="padding:9px 16px">Apply</button>
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
