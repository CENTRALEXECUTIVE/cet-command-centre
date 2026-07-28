@extends('layouts.app')
@section('title', 'Profit')

@php
    $m = $month->format('Y-m');
    $start = $month->format('Y-m-d');
    $end = $month->copy()->endOfMonth()->format('Y-m-d');
@endphp

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Profit &amp; commission</h1>
            <p class="page-sub">Turnover, driver cost, commission and net profit for {{ $month->format('F Y') }}. Jobs that have run (not cancelled / no-show).</p>
        </div>
        <form method="GET" action="{{ route('reports.profit') }}">
            <input type="month" name="month" value="{{ $m }}" onchange="this.form.submit()" style="width:auto">
        </form>
    </div>

    {{-- Money statement: a clean top-to-bottom P&L for the month. --}}
    @php $money = 'font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap'; @endphp
    <div class="card" style="margin:14px 0;padding:6px 18px">
        <a href="{{ route('reports.revenue', ['start' => $start, 'end' => $end]) }}" style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:13px 0;border-bottom:1px solid rgba(128,128,128,.14);text-decoration:none;color:inherit">
            <span>Turnover <span class="muted" style="font-size:12px">· {{ $data['jobs'] }} job{{ $data['jobs'] === 1 ? '' : 's' }}</span></span>
            <span style="{{ $money }}">£{{ number_format($data['revenue'], 0) }}</span>
        </a>
        <a href="{{ route('payroll.index', ['month' => $m]) }}" style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:13px 0;border-bottom:1px solid rgba(128,128,128,.14);text-decoration:none;color:inherit">
            <span>Driver cost</span>
            <span style="{{ $money }};color:#b8860b">−£{{ number_format($data['driver_cost'], 0) }}</span>
        </a>
        <a href="#per-driver" style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:14px 0;border-bottom:1px solid rgba(128,128,128,.14);text-decoration:none;color:inherit">
            <span style="font-weight:700">Commission <span class="muted" style="font-size:12px;font-weight:400">· {{ $data['margin_pct'] }}% margin</span></span>
            <span style="{{ $money }};font-weight:800;font-size:17px">£{{ number_format($data['commission'], 0) }}</span>
        </a>
        <a href="{{ route('reports.ads', ['start' => $start, 'end' => $end]) }}" style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:13px 0;border-bottom:2px solid rgba(128,128,128,.22);text-decoration:none;color:inherit">
            <span>Ad spend</span>
            <span style="{{ $money }};color:#b8860b">−£{{ number_format($data['ad_spend'], 0) }}</span>
        </a>
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:15px 0 13px">
            <span style="font-weight:800;font-size:17px">Net profit <span class="muted" style="font-size:12px;font-weight:400">· {{ $data['net_margin_pct'] }}% of turnover</span></span>
            <span style="{{ $money }};font-weight:800;font-size:26px;color:{{ $data['net_profit'] >= 0 ? '#1f7a44' : '#b32020' }}">£{{ number_format($data['net_profit'], 0) }}</span>
        </div>
    </div>

    @if($data['cash_to_drivers'] > 0)
        <p class="hint" style="margin:0 0 16px">Of the turnover, <strong>£{{ number_format($data['cash_to_drivers'], 0) }}</strong> is cash paid straight to drivers on cash jobs — the business keeps the deposit on those. Card &amp; account fares come to the company.</p>
    @endif

    <div id="per-driver" class="card" style="scroll-margin-top:16px">
        <h2 style="margin:0 0 4px">Commission per driver</h2>
        @if($data['per_driver']->isEmpty())
            <p class="muted mb-0">No jobs with a fare this month. Set driver pay in <a href="{{ route('payroll.index', ['month' => $m]) }}">Payroll</a> and it appears here.</p>
        @else
            @foreach($data['per_driver'] as $d)
                @include('reports.partials.commission-row', ['r' => $d, 'href' => route('bookings.index', ['driver' => $d['name'], 'month' => $m])])
            @endforeach
        @endif
    </div>

    @if($data['per_account']->isNotEmpty())
        <div class="card">
            <h2 style="margin:0 0 4px">Commission per corporate account</h2>
            @foreach($data['per_account'] as $a)
                @include('reports.partials.commission-row', ['r' => $a])
            @endforeach
        </div>
    @endif

    <details style="margin-top:14px">
        <summary class="muted" style="font-size:13px;cursor:pointer">How these figures are worked out</summary>
        <p class="hint" style="margin:8px 0 0">
            <strong>Commission</strong> = turnover − driver cost (the margin the business makes on each job).
            <strong>Net profit</strong> = commission − ad spend.
            Driver cost = pay handed out on card/account jobs plus the cash a driver keeps on a cash job.
            Set pay on each booking or in <a href="{{ route('payroll.index', ['month' => $m]) }}">Payroll</a>; ad spend comes from the <a href="{{ route('reports.ads', ['start' => $start, 'end' => $end]) }}">Google Ads</a> import.
        </p>
    </details>
@endsection
