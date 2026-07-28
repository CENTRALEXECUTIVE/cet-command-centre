@extends('layouts.app')
@section('title', 'Google Ads Dashboard')

@section('content')
    <h1 class="page-title">Google Ads Dashboard</h1>
    <p class="page-sub">{{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</p>

    <form method="GET" action="{{ route('reports.ads') }}" class="toolbar">
        <input type="date" name="start" value="{{ $start->toDateString() }}" style="width:auto">
        <input type="date" name="end" value="{{ $end->toDateString() }}" style="width:auto">
        <button class="btn btn-dark" type="submit" style="padding:9px 16px">Apply</button>
    </form>

    @if(!empty($data['alerts']))
        <div class="alert alert-success">
            <strong>Budget triggers reached:</strong>
            @foreach($data['alerts'] as $alert)
                {{ $alert['metric'] }} {{ is_float($alert['value']) ? '£'.number_format($alert['value'],0) : $alert['value'] }}
                (≥ {{ is_float($alert['threshold']) ? '£'.number_format($alert['threshold'],0) : $alert['threshold'] }}){{ !$loop->last ? ' ·' : '' }}
            @endforeach
        </div>
    @endif

    <p class="hint" style="margin:0 0 14px">Money into the business vs Google Ads spend, {{ $start->format('d M') }}–{{ $end->format('d M Y') }}. <strong>Revenue</strong> is every completed booking in this period (all bookings, not only ad-driven); <strong>ROAS</strong> is that revenue ÷ ad spend. For the full P&amp;L see <a href="{{ route('reports.profit', ['month' => $start->format('Y-m')]) }}">Profit</a>.</p>

    <div class="grid grid-3" style="margin-bottom:16px">
        <div class="stat"><div class="n">£{{ number_format($data['spend'], 2) }}</div><div class="l">Google Ads spend</div></div>
        <div class="stat"><div class="n">£{{ number_format($data['revenue'], 2) }}</div><div class="l">Business revenue · {{ $data['jobs'] }} jobs</div></div>
        <div class="stat"><div class="n">{{ $data['roas'] ? $data['roas'].'x' : '—' }}</div><div class="l">Revenue ÷ ad spend</div></div>
    </div>
    <div class="grid grid-3">
        <div class="stat"><div class="n">{{ $data['conversions'] }}</div><div class="l">Conversions / {{ $data['thresholds']['conversions'] }}</div></div>
        <div class="stat"><div class="n">{{ $data['jobs'] }}</div><div class="l">Jobs / {{ $data['thresholds']['jobs'] }}</div></div>
        <div class="stat"><div class="n">{{ $data['cost_per_conversion'] ? '£'.number_format($data['cost_per_conversion'],2) : '—' }}</div><div class="l">Cost / Conversion</div></div>
    </div>

    <div class="card" style="margin-top:20px">
        <h2>Budget Triggers</h2>
        <table>
            <thead><tr><th>Metric</th><th>Current</th><th>Threshold</th><th>Status</th></tr></thead>
            <tbody>
                @php $t = $data['thresholds']; @endphp
                <tr><td>Conversions</td><td>{{ $data['conversions'] }}</td><td>{{ $t['conversions'] }}</td>
                    <td><span class="badge badge-{{ $data['conversions'] >= $t['conversions'] ? 'complete' : 'pending' }}">{{ $data['conversions'] >= $t['conversions'] ? 'Reached' : 'Below' }}</span></td></tr>
                <tr><td>Jobs</td><td>{{ $data['jobs'] }}</td><td>{{ $t['jobs'] }}</td>
                    <td><span class="badge badge-{{ $data['jobs'] >= $t['jobs'] ? 'complete' : 'pending' }}">{{ $data['jobs'] >= $t['jobs'] ? 'Reached' : 'Below' }}</span></td></tr>
                <tr><td>Revenue</td><td>£{{ number_format($data['revenue'], 0) }}</td><td>£{{ number_format($t['revenue'], 0) }}</td>
                    <td><span class="badge badge-{{ $data['revenue'] >= $t['revenue'] ? 'complete' : 'pending' }}">{{ $data['revenue'] >= $t['revenue'] ? 'Reached' : 'Below' }}</span></td></tr>
            </tbody>
        </table>
    </div>
@endsection
