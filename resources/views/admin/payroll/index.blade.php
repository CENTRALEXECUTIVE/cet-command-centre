@extends('layouts.app')
@section('title', 'Payroll')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Driver payroll</h1>
            <p class="page-sub">Who's been paid, and what's still owed, for {{ $month->format('F Y') }}. Pay is set on each booking.</p>
            <p style="margin:6px 0 0;font-weight:600">
                <span class="mono">{{ $completedCount }}</span> job{{ $completedCount === 1 ? '' : 's' }} completed this month ·
                <span style="color:#1f7a44">{{ $paidCount }} driver paid</span>@if($missingPay->count() > 0) ·
                <a href="#missing-pay" style="color:#b8860b;text-decoration:none">{{ $missingPay->count() }} still need pay set →</a>@endif
            </p>
            @if($totals['remaining'] > 0)
                <p class="hint" style="margin:2px 0 0">…plus <a href="{{ route('payroll.index', ['month' => $month->format('Y-m'), 'filter' => 'owed']) }}" style="color:#b8860b">£{{ number_format($totals['remaining'], 2) }} still owed</a> on jobs that already have pay set.</p>
            @endif
        </div>
        <form method="GET" action="{{ route('payroll.index') }}">
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
        </form>
    </div>

    @php $m = $month->format('Y-m'); @endphp
    <div class="deck" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:18px">
        <a href="{{ route('payroll.index', ['month' => $m, 'filter' => 'all']) }}" class="kpi" style="text-decoration:none;color:inherit;{{ $filter === 'all' ? 'outline:2px solid var(--accent,#FBBA2A);outline-offset:2px' : '' }}"><div class="kpi-ico">💷</div><div class="kpi-n">£{{ number_format($totals['pay'], 2) }}</div><div class="kpi-l">Total driver pay</div></a>
        <a href="{{ route('payroll.index', ['month' => $m, 'filter' => 'paid']) }}" class="kpi ok" style="text-decoration:none;color:inherit;{{ $filter === 'paid' ? 'outline:2px solid #1f7a44;outline-offset:2px' : '' }}"><div class="kpi-ico">✅</div><div class="kpi-n">£{{ number_format($totals['paid'], 2) }}</div><div class="kpi-l">Paid out</div></a>
        <a href="{{ route('payroll.index', ['month' => $m, 'filter' => 'owed']) }}" class="kpi warn" style="text-decoration:none;color:inherit;{{ $filter === 'owed' ? 'outline:2px solid #b8860b;outline-offset:2px' : '' }}"><div class="kpi-ico">⏳</div><div class="kpi-n">£{{ number_format($totals['remaining'], 2) }}</div><div class="kpi-l">Still owed</div></a>
        <a href="{{ route('payroll.index', ['month' => $m, 'filter' => 'tips']) }}" class="kpi" style="text-decoration:none;color:inherit;{{ $filter === 'tips' ? 'outline:2px solid var(--accent,#FBBA2A);outline-offset:2px' : '' }}"><div class="kpi-ico">💛</div><div class="kpi-n">£{{ number_format($totals['tips'], 2) }}</div><div class="kpi-l">Tips @if($totals['card_tips_owed'] > 0)· £{{ number_format($totals['card_tips_owed'], 2) }} card owed @endif</div></a>
    </div>
    @if($filter !== 'all')
        <p class="hint" style="margin:-8px 0 14px">Showing <strong>{{ $filter === 'paid' ? 'drivers paid' : ($filter === 'owed' ? 'drivers still owed' : 'drivers with tips') }}</strong> · <a href="{{ route('payroll.index', ['month' => $m]) }}">show all</a></p>
    @endif

    @if($missingPay->isNotEmpty())
        <div id="missing-pay" class="card" style="scroll-margin-top:16px;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.07);margin-bottom:16px">
            <strong>⚠ {{ $missingPay->count() }} completed job(s) have no driver pay set</strong>
            <p class="hint" style="margin:4px 0 8px">Type the driver's pay and tap <strong>Set</strong> — the job drops off this list and you stay right here.</p>
            <div>
                @foreach($missingPay as $b)
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:7px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                        <a href="{{ route('bookings.show', $b) }}#payroll" class="mono">{{ $b->external_reference ?? $b->reference }}</a>
                        <span style="flex:1;min-width:180px">{{ $b->payrollDriverName() }} <span class="muted">· {{ $b->pickup_at->format('D d M, H:i') }} · {{ $b->displayName() }}</span></span>
                        <form method="POST" action="{{ route('bookings.payroll', $b) }}" style="display:flex;gap:6px;align-items:center;margin:0">
                            @csrf
                            <input type="hidden" name="action" value="set">
                            <input type="hidden" name="from" value="payroll">
                            <input type="hidden" name="month" value="{{ $m }}">
                            <span class="muted">£</span>
                            <input type="number" name="amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00" required
                                   style="width:90px;padding:5px 8px" aria-label="Driver pay for {{ $b->reference }}">
                            <button class="btn btn-primary" style="padding:5px 14px;font-size:13px">Set</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @forelse($drivers as $d)
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
                <h2 style="margin:0">{{ $d['name'] }}@if($d['extra'] ?? false) <span class="badge" style="background:#5b2bc7;color:#fff;font-size:11px;vertical-align:middle">extra car</span>@endif</h2>
                <div style="font-size:14px">
                    £{{ number_format($d['pay'], 2) }} total
                    · <span style="color:#1f7a44">£{{ number_format($d['paid'], 2) }} paid</span>
                    · @if($d['remaining'] > 0)<strong style="color:#b8860b">£{{ number_format($d['remaining'], 2) }} owed</strong>@else<span class="badge badge-complete">Settled</span>@endif
                    @if($d['tips'] > 0) · <span style="color:#b8860b">💛 £{{ number_format($d['tips'], 2) }} tips @if($d['card_tips_owed'] > 0)(£{{ number_format($d['card_tips_owed'], 2) }} card owed)@endif</span>@endif
                </div>
            </div>
            <div style="margin-top:10px">
                <div style="overflow-x:auto">
                @if($d['extra'] ?? false)
                <table>
                    <thead><tr><th>Job</th><th>Date</th><th>Customer</th><th>Car</th><th>Pays</th><th>Paid</th><th>Remaining</th><th></th></tr></thead>
                    <tbody>
                    @foreach($d['car_jobs'] as $r)
                        @php $e = $r['entry']; $rem = max(0, (float)($e['pay'] ?? 0) - (float)($e['paid'] ?? 0)); @endphp
                        <tr class="rowlink" data-href="{{ route('bookings.show', $r['booking']) }}">
                            <td class="mono">{{ $r['booking']->external_reference ?? $r['booking']->reference }}</td>
                            <td>{{ $r['booking']->pickup_at->format('d M, H:i') }}</td>
                            <td>{{ $r['booking']->displayName() }}</td>
                            <td>Car {{ $r['car'] }}</td>
                            <td>£{{ number_format((float)($e['pay'] ?? 0), 2) }}</td>
                            <td>£{{ number_format((float)($e['paid'] ?? 0), 2) }}</td>
                            <td>@if($rem > 0)<strong style="color:#b8860b">£{{ number_format($rem, 2) }}</strong>@else<span class="badge badge-complete">✓</span>@endif</td>
                            <td><a href="{{ route('bookings.show', $r['booking']) }}" style="font-size:13px" data-norowlink>Open →</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @else
                <table>
                    <thead><tr><th>Job</th><th>Date</th><th>Customer</th><th>Pays</th><th>Paid</th><th>Remaining</th><th>Tips</th><th></th></tr></thead>
                    <tbody>
                    @foreach($d['jobs'] as $b)
                        <tr class="rowlink" data-href="{{ route('bookings.show', $b) }}">
                            <td class="mono">{{ $b->external_reference ?? $b->reference }}</td>
                            <td>{{ $b->pickup_at->format('d M, H:i') }}</td>
                            <td>{{ $b->displayName() }}</td>
                            <td>{{ $b->driverPay() === null ? '—' : '£'.number_format($b->driverPay(), 2) }}</td>
                            <td>@if($b->driverSettledByCustomer())<span class="muted">cash</span>@else£{{ number_format($b->driverPaidAmount(), 2) }}@endif</td>
                            <td>@if($b->driverSettledByCustomer())<span class="muted" title="Customer paid the driver directly">paid by customer</span>@elseif(($b->driverPayRemaining() ?? 0) > 0)<strong style="color:#b8860b">£{{ number_format($b->driverPayRemaining(), 2) }}</strong>@else<span class="badge badge-complete">✓</span>@endif</td>
                            <td>@if($b->tipsTotal() > 0)💛 £{{ number_format($b->tipsTotal(), 2) }}@else<span class="muted">—</span>@endif</td>
                            <td style="white-space:nowrap">
                                @if($b->driverPay() !== null && ! $b->driverSettledByCustomer() && ($b->driverPayRemaining() ?? 0) > 0)
                                    <form method="POST" action="{{ route('bookings.payroll', $b) }}" style="display:inline" data-norowlink>
                                        @csrf
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="from" value="payroll">
                                        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                        <button type="submit" class="btn btn-primary" style="padding:5px 12px;font-size:13px">✓ Mark paid</button>
                                    </form>
                                @endif
                                <a href="{{ route('bookings.show', $b) }}" style="font-size:13px;margin-left:6px" data-norowlink>Open →</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
                </div>
            </div>
        </div>
    @empty
        <div class="card"><p class="muted mb-0">No driver pay recorded for {{ $month->format('F Y') }} yet — set "Job pays the driver" on any booking and it appears here.</p></div>
    @endforelse

    {{-- Whole-row click opens the booking (pulls all its info), while the
         "Mark paid" button and "Open →" link keep their own action. --}}
    <style>tr.rowlink{cursor:pointer}tr.rowlink:hover td{background:rgba(251,186,42,.06)}</style>
    <script>
        document.querySelectorAll('tr.rowlink').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('a,button,form,input,[data-norowlink]')) return;
                var href = row.getAttribute('data-href');
                if (href) window.location = href;
            });
        });
    </script>
@endsection
