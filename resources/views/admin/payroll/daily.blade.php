@extends('layouts.app')
@section('title', 'Cash & pay — day')

@php
    $d = $day->format('Y-m-d');
    $money = 'font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap';
@endphp

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Cash &amp; pay — {{ $day->format('D d M Y') }}</h1>
            <p class="page-sub">End-of-shift close. Each driver settles to exactly their earnings: cash they're holding, what the job pays, and the one figure to hand over (or collect back).</p>
        </div>
        <form method="GET" action="{{ route('payroll.daily') }}" style="display:flex;gap:6px;align-items:center">
            <a href="{{ route('payroll.daily', ['date' => $day->copy()->subDay()->format('Y-m-d')]) }}" class="btn btn-ghost" style="padding:6px 10px">←</a>
            <input type="date" name="date" value="{{ $d }}" onchange="this.form.submit()" style="width:auto">
            <a href="{{ route('payroll.daily', ['date' => $day->copy()->addDay()->format('Y-m-d')]) }}" class="btn btn-ghost" style="padding:6px 10px">→</a>
        </form>
    </div>

    <div class="grid grid-4" style="margin:14px 0;gap:10px">
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Jobs today</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ $totals['jobs'] }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">💷 Cash collected</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">£{{ number_format($totals['cash_collected'], 2) }}</div>
        </div>
        <div class="card" style="padding:14px;border-left:4px solid #1f7a44">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">To pay out</div>
            <div style="font-size:26px;font-weight:800;color:#1f7a44;{{ $money }}">£{{ number_format($totals['to_pay_out'], 2) }}</div>
        </div>
        <div class="card" style="padding:14px;border-left:4px solid #b8860b">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">To collect back</div>
            <div style="font-size:26px;font-weight:800;color:#b8860b;{{ $money }}">£{{ number_format($totals['to_collect_back'], 2) }}</div>
        </div>
    </div>

    @forelse($drivers as $driver)
        <div class="card" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <h2 style="margin:0">{{ $driver['name'] }} <span class="muted" style="font-size:14px;font-weight:500">· {{ $driver['count'] }} job{{ $driver['count'] === 1 ? '' : 's' }}</span></h2>
                <div style="text-align:right">
                    @if($driver['net'] > 0.001)
                        <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Pay {{ $driver['name'] }}</div>
                        <div style="font-size:22px;font-weight:800;color:#1f7a44;{{ $money }}">+£{{ number_format($driver['net'], 2) }}</div>
                    @elseif($driver['net'] < -0.001)
                        <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Collect from {{ $driver['name'] }}</div>
                        <div style="font-size:22px;font-weight:800;color:#b8860b;{{ $money }}">−£{{ number_format(abs($driver['net']), 2) }}</div>
                    @else
                        <span class="badge badge-complete">Settled</span>
                    @endif
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
                <span class="bh-chip">💷 £{{ number_format($driver['cash'], 2) }} cash in hand</span>
                <span class="bh-chip">💰 £{{ number_format($driver['pay'], 2) }} their pay</span>
                @if($driver['card_tips'] > 0)<span class="bh-chip">💛 £{{ number_format($driver['card_tips'], 2) }} card tips</span>@endif
            </div>

            <div style="overflow-x:auto;margin-top:10px">
                <table>
                    <thead><tr><th>Time</th><th>Customer</th><th>Payment</th><th>Cash held</th><th>Pay</th></tr></thead>
                    <tbody>
                    @foreach($driver['jobs'] as $b)
                        <tr class="rowlink" data-href="{{ route('bookings.show', $b) }}" style="cursor:pointer">
                            <td>{{ $b->pickup_at->format('H:i') }}</td>
                            <td>{{ $b->displayName() }}</td>
                            <td>{{ $b->driverSettledByCustomer() ? 'Cash (driver holds)' : ($b->payment_method?->label() ?? '—') }}</td>
                            <td style="{{ $money }}">{{ $b->driverSettledByCustomer() && $b->cashDueToDriver() ? '£'.number_format($b->cashDueToDriver(), 2) : '—' }}</td>
                            <td style="{{ $money }}">{{ $b->driverPay() === null ? '—' : '£'.number_format($b->driverPay(), 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card"><p class="muted mb-0">No jobs with a driver ran on {{ $day->format('D d M Y') }}.</p></div>
    @endforelse

    <p class="muted" style="font-size:12px;margin-top:6px">Net = pay − cash in hand − already paid + card tips owed. Cash jobs settle with the driver directly; a driver who collected more cash than their pay hands the difference back.</p>

    <style>
        tr.rowlink:hover td{background:rgba(251,186,42,.08)}
        .bh-chip{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(128,128,128,.12);font-size:13px;font-weight:600;white-space:nowrap}
    </style>
    <script>
        document.addEventListener('click', function (e) {
            var row = e.target.closest('tr.rowlink');
            if (!row || e.target.closest('a,button,form,input,[data-norowlink]')) return;
            var href = row.getAttribute('data-href');
            if (href) window.location = href;
        });
    </script>
@endsection
