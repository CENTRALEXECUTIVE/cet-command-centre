@extends('layouts.app')
@section('title', 'Profit')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Profit</h1>
            <p class="page-sub">Turnover, driver cost and what's left, for {{ $month->format('F Y') }}. Jobs that have run (not cancelled / no-show).</p>
        </div>
        <form method="GET" action="{{ route('reports.profit') }}">
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
        </form>
    </div>

    <div class="deck" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin:14px 0 6px">
        <div class="kpi"><div class="kpi-ico">💷</div><div class="kpi-n">£{{ number_format($data['revenue'], 0) }}</div><div class="kpi-l">Turnover · {{ $data['jobs'] }} job{{ $data['jobs'] === 1 ? '' : 's' }}</div></div>
        <div class="kpi warn"><div class="kpi-ico">🚗</div><div class="kpi-n">£{{ number_format($data['driver_cost'], 0) }}</div><div class="kpi-l">Driver cost</div></div>
        <div class="kpi ok"><div class="kpi-ico">📈</div><div class="kpi-n">£{{ number_format($data['profit'], 0) }}</div><div class="kpi-l">Profit · {{ $data['margin_pct'] }}% margin</div></div>
    </div>

    @if($data['cash_to_drivers'] > 0)
        <p class="hint" style="margin:0 0 16px">Of the turnover, <strong>£{{ number_format($data['cash_to_drivers'], 0) }}</strong> is cash paid straight to drivers on cash jobs — the business keeps the deposit on those. Card &amp; account fares come to the company.</p>
    @endif

    <div class="card">
        <h2 style="margin:0 0 8px">Per driver</h2>
        @if($data['per_driver']->isEmpty())
            <p class="muted mb-0">No jobs with a fare this month.</p>
        @else
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Driver</th><th>Jobs</th><th>Fares</th><th>Driver cost</th><th>Profit</th></tr></thead>
                <tbody>
                @foreach($data['per_driver'] as $d)
                    <tr>
                        <td>{{ $d['name'] }}</td>
                        <td>{{ $d['jobs'] }}</td>
                        <td>£{{ number_format($d['fares'], 2) }}</td>
                        <td>£{{ number_format($d['cost'], 2) }}</td>
                        <td><strong style="color:{{ $d['profit'] >= 0 ? '#1f7a44' : '#b32020' }}">£{{ number_format($d['profit'], 2) }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    @if($data['per_account']->isNotEmpty())
        <div class="card">
            <h2 style="margin:0 0 8px">Per corporate account</h2>
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Account</th><th>Jobs</th><th>Fares</th><th>Driver cost</th><th>Profit</th></tr></thead>
                <tbody>
                @foreach($data['per_account'] as $a)
                    <tr>
                        <td>{{ $a['name'] }}</td>
                        <td>{{ $a['jobs'] }}</td>
                        <td>£{{ number_format($a['fares'], 2) }}</td>
                        <td>£{{ number_format($a['cost'], 2) }}</td>
                        <td><strong style="color:{{ $a['profit'] >= 0 ? '#1f7a44' : '#b32020' }}">£{{ number_format($a['profit'], 2) }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif

    <p class="hint" style="margin-top:12px">Driver cost = pay the business hands out on card/account jobs, plus the cash a driver keeps on a cash job. Set driver pay on each booking (or in Payroll). Fares come from each job's agreed/quoted price.</p>
@endsection
