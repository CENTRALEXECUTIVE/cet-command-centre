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

    {{-- The headline: what the business actually made this month. --}}
    <div class="card" style="text-align:center;margin:14px 0;padding:20px">
        <div class="muted" style="font-size:13px;letter-spacing:.04em;text-transform:uppercase">Net profit — {{ $month->format('F Y') }}</div>
        <div style="font-size:44px;font-weight:800;color:{{ $data['net_profit'] >= 0 ? '#1f7a44' : '#b32020' }};line-height:1.1;margin:4px 0">£{{ number_format($data['net_profit'], 2) }}</div>
        <div class="muted" style="font-size:13px">Commission £{{ number_format($data['commission'], 0) }} − ad spend £{{ number_format($data['ad_spend'], 0) }} · {{ $data['net_margin_pct'] }}% of turnover</div>
    </div>

    {{-- Clickable tiles — each opens the detail behind the number. --}}
    <div class="deck" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:16px">
        <a class="kpi" style="text-decoration:none;color:inherit" href="{{ route('reports.revenue', ['start' => $start, 'end' => $end]) }}">
            <div class="kpi-ico">💷</div><div class="kpi-n">£{{ number_format($data['revenue'], 0) }}</div><div class="kpi-l">Turnover · {{ $data['jobs'] }} job{{ $data['jobs'] === 1 ? '' : 's' }} →</div>
        </a>
        <a class="kpi warn" style="text-decoration:none;color:inherit" href="{{ route('payroll.index', ['month' => $m]) }}">
            <div class="kpi-ico">🚗</div><div class="kpi-n">£{{ number_format($data['driver_cost'], 0) }}</div><div class="kpi-l">Driver cost → Payroll</div>
        </a>
        <a class="kpi ok" style="text-decoration:none;color:inherit" href="#per-driver">
            <div class="kpi-ico">💰</div><div class="kpi-n">£{{ number_format($data['commission'], 0) }}</div><div class="kpi-l">Commission · {{ $data['margin_pct'] }}% →</div>
        </a>
        <a class="kpi" style="text-decoration:none;color:inherit" href="{{ route('reports.ads', ['start' => $start, 'end' => $end]) }}">
            <div class="kpi-ico">📣</div><div class="kpi-n">£{{ number_format($data['ad_spend'], 0) }}</div><div class="kpi-l">Ad spend → Google Ads</div>
        </a>
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
                @include('reports.partials.commission-row', ['r' => $d])
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

    <p class="hint" style="margin-top:12px">
        <strong>Commission</strong> = turnover − driver cost (the margin the business makes on each job).
        <strong>Net profit</strong> = commission − ad spend.
        Driver cost = pay handed out on card/account jobs plus the cash a driver keeps on a cash job.
        Set pay on each booking or in <a href="{{ route('payroll.index', ['month' => $m]) }}">Payroll</a>; ad spend comes from the <a href="{{ route('reports.ads', ['start' => $start, 'end' => $end]) }}">Google Ads</a> import.
    </p>
@endsection
