@extends('layouts.app')
@section('title', 'Payroll')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Driver payroll</h1>
            <p class="page-sub">Who's been paid, and what's still owed, for {{ $month->format('F Y') }}. Pay is set on each booking.</p>
        </div>
        <form method="GET" action="{{ route('payroll.index') }}">
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
        </form>
    </div>

    <div class="deck" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px">
        <div class="kpi"><div class="kpi-ico">💷</div><div class="kpi-n">£{{ number_format($totals['pay'], 2) }}</div><div class="kpi-l">Total driver pay</div></div>
        <div class="kpi ok"><div class="kpi-ico">✅</div><div class="kpi-n">£{{ number_format($totals['paid'], 2) }}</div><div class="kpi-l">Paid out</div></div>
        <div class="kpi warn"><div class="kpi-ico">⏳</div><div class="kpi-n">£{{ number_format($totals['remaining'], 2) }}</div><div class="kpi-l">Still owed</div></div>
        <div class="kpi"><div class="kpi-ico">💛</div><div class="kpi-n">£{{ number_format($totals['tips'], 2) }}</div><div class="kpi-l">Tips @if($totals['card_tips_owed'] > 0)· £{{ number_format($totals['card_tips_owed'], 2) }} card owed @endif</div></div>
    </div>

    @if($missingPay->isNotEmpty())
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.07);margin-bottom:16px">
            <strong>⚠ {{ $missingPay->count() }} completed job(s) have no driver pay set</strong>
            <div style="margin-top:8px">
                @foreach($missingPay as $b)
                    <div style="display:flex;gap:12px;align-items:baseline;padding:5px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                        <a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->external_reference ?? $b->reference }}</a>
                        <span style="flex:1">{{ $b->payrollDriverName() }} <span class="muted">· {{ $b->pickup_at->format('D d M, H:i') }} · {{ $b->displayName() }}</span></span>
                        <a href="{{ route('bookings.show', $b) }}" class="btn btn-light" style="padding:4px 12px;font-size:12px">Set pay →</a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @forelse($drivers as $d)
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
                <h2 style="margin:0">{{ $d['name'] }}</h2>
                <div style="font-size:14px">
                    £{{ number_format($d['pay'], 2) }} total
                    · <span style="color:#1f7a44">£{{ number_format($d['paid'], 2) }} paid</span>
                    · @if($d['remaining'] > 0)<strong style="color:#b8860b">£{{ number_format($d['remaining'], 2) }} owed</strong>@else<span class="badge badge-complete">Settled</span>@endif
                    @if($d['tips'] > 0) · <span style="color:#b8860b">💛 £{{ number_format($d['tips'], 2) }} tips @if($d['card_tips_owed'] > 0)(£{{ number_format($d['card_tips_owed'], 2) }} card owed)@endif</span>@endif
                </div>
            </div>
            <div style="margin-top:10px">
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Job</th><th>Date</th><th>Customer</th><th>Pays</th><th>Paid</th><th>Remaining</th><th>Tips</th><th></th></tr></thead>
                    <tbody>
                    @foreach($d['jobs'] as $b)
                        <tr>
                            <td class="mono">{{ $b->external_reference ?? $b->reference }}</td>
                            <td>{{ $b->pickup_at->format('d M, H:i') }}</td>
                            <td>{{ $b->displayName() }}</td>
                            <td>{{ $b->driverPay() === null ? '—' : '£'.number_format($b->driverPay(), 2) }}</td>
                            <td>£{{ number_format($b->driverPaidAmount(), 2) }}</td>
                            <td>@if(($b->driverPayRemaining() ?? 0) > 0)<strong style="color:#b8860b">£{{ number_format($b->driverPayRemaining(), 2) }}</strong>@else<span class="badge badge-complete">✓</span>@endif</td>
                            <td>@if($b->tipsTotal() > 0)💛 £{{ number_format($b->tipsTotal(), 2) }}@else<span class="muted">—</span>@endif</td>
                            <td><a href="{{ route('bookings.show', $b) }}" style="font-size:13px">Open →</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    @empty
        <div class="card"><p class="muted mb-0">No driver pay recorded for {{ $month->format('F Y') }} yet — set "Job pays the driver" on any booking and it appears here.</p></div>
    @endforelse
@endsection
