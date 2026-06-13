@extends('layouts.app')
@section('title', 'Revenue Reports')

@section('content')
    <h1 class="page-title">Revenue Reports</h1>
    <p class="page-sub">{{ $start->format('d M Y') }} – {{ $end->format('d M Y') }} (completed jobs)</p>

    <form method="GET" action="{{ route('reports.revenue') }}" class="toolbar">
        <input type="date" name="start" value="{{ $start->toDateString() }}" style="width:auto">
        <input type="date" name="end" value="{{ $end->toDateString() }}" style="width:auto">
        <button class="btn btn-dark" type="submit" style="padding:9px 16px">Apply</button>
    </form>

    @php $c = $comparison['current']; $p = $comparison['previous']; @endphp
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
        <div class="stat"><div class="n">{{ $c['jobs'] }}</div><div class="l">Jobs (prev {{ $p['jobs'] }})</div></div>
        <div class="stat"><div class="n">£{{ number_format($c['average_fare'], 2) }}</div><div class="l">Average Fare</div></div>
    </div>

    <div class="card">
        <h2>Earnings by Driver</h2>
        @if($byDriver->isEmpty())<p class="muted mb-0">No data.</p>@else
            <table>
                <thead><tr><th>Driver</th><th>Jobs</th><th class="right">Revenue</th></tr></thead>
                <tbody>
                    @foreach($byDriver as $row)
                        <tr><td>{{ $row->driver?->name ?? '—' }}</td><td>{{ $row->jobs }}</td>
                            <td class="right">£{{ number_format($row->revenue, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>By Vehicle Type</h2>
            @if($byVehicle->isEmpty())<p class="muted mb-0">No data.</p>@else
                <table>
                    <thead><tr><th>Vehicle</th><th>Jobs</th><th class="right">Revenue</th></tr></thead>
                    <tbody>
                        @foreach($byVehicle as $row)
                            <tr><td>{{ $row->vehicleType?->name ?? '—' }}</td><td>{{ $row->jobs }}</td>
                                <td class="right">£{{ number_format($row->revenue, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <h2>Top Routes</h2>
            @if($topRoutes->isEmpty())<p class="muted mb-0">No data.</p>@else
                <table>
                    <thead><tr><th>Route</th><th>Jobs</th></tr></thead>
                    <tbody>
                        @foreach($topRoutes as $row)
                            <tr><td>{{ \Illuminate\Support\Str::limit($row->pickup_address, 18) }} →
                                {{ \Illuminate\Support\Str::limit($row->destination_address, 18) }}</td>
                                <td>{{ $row->jobs }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
