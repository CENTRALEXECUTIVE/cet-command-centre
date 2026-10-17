@extends('layouts.app')
@section('title', 'New customers by month')

@section('content')
    <h1 class="page-title">New customers by month</h1>
    <p class="page-sub">{{ $start->format('M Y') }} – {{ $end->format('M Y') }}</p>

    <form method="GET" action="{{ route('reports.customers') }}" class="toolbar">
        <input type="date" name="start" value="{{ $start->toDateString() }}" style="width:auto">
        <input type="date" name="end" value="{{ $end->toDateString() }}" style="width:auto">
        <button class="btn btn-dark" type="submit" style="padding:9px 16px">Apply</button>
    </form>

    <p class="hint" style="margin:0 0 14px">
        A customer counts as <strong>new</strong> in the month of their <strong>first-ever booking</strong>.
        <strong>Via website</strong> is the channel Google Ads drives; <strong>other</strong> is phone / WhatsApp / repeat.
        Each month is lined up with that month's <strong>Google Ads spend</strong> (from the Ads report you
        <a href="{{ route('imports.index') }}">import</a>) and the <strong>cost to win one new website customer</strong> —
        the number that shows whether the ads are paying off. Pair it with the <a href="{{ route('reports.ads') }}">Ads dashboard</a> for revenue/ROAS.
    </p>

    @php
        $cpw = ($totals['web'] > 0 && $totals['ad_spend'] > 0) ? $totals['ad_spend'] / $totals['web'] : null;
        $maxNew = max(1, max(array_column($rows, 'new_customers')));
    @endphp

    <div class="grid grid-3" style="margin-bottom:16px">
        <div class="stat"><div class="n">{{ $totals['new_customers'] }}</div><div class="l">New customers · {{ $totals['web'] }} web / {{ $totals['other'] }} other</div></div>
        <div class="stat"><div class="n">£{{ number_format($totals['ad_spend'], 2) }}</div><div class="l">Google Ads spend</div></div>
        <div class="stat"><div class="n">{{ $cpw !== null ? '£'.number_format($cpw, 2) : '—' }}</div><div class="l">Cost / new website customer</div></div>
    </div>

    <div class="card">
        <h2>Month by month</h2>
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align:right">New</th>
                    <th>&nbsp;</th>
                    <th style="text-align:right">Website</th>
                    <th style="text-align:right">Other</th>
                    <th style="text-align:right">Ad spend</th>
                    <th style="text-align:right">Ad conv.</th>
                    <th style="text-align:right" title="Ad spend ÷ new website customers">Cost / web</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                    @php
                        $barPct = (int) round($r['new_customers'] / $maxNew * 100);
                        // Flag a month where we spent on ads but won no new website customers.
                        $wasteful = $r['ad_spend'] > 0 && $r['web'] === 0;
                    @endphp
                    <tr>
                        <td style="white-space:nowrap;font-weight:600">{{ $r['label'] }}</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700">{{ $r['new_customers'] }}</td>
                        <td style="width:120px;min-width:90px">
                            <span style="display:block;height:9px;border-radius:5px;background:linear-gradient(90deg,#FBBA2A,#e0a310);width:{{ $barPct }}%"></span>
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $r['web'] }}</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;color:#777">{{ $r['other'] }}</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $r['ad_spend'] > 0 ? '£'.number_format($r['ad_spend'], 2) : '—' }}</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;color:#777">{{ $r['ad_conversions'] ?: '—' }}</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;{{ $wasteful ? 'color:#b32020;font-weight:700' : '' }}">
                            @if($r['cost_per_web'] !== null)
                                £{{ number_format($r['cost_per_web'], 2) }}
                            @elseif($wasteful)
                                £{{ number_format($r['ad_spend'], 2) }} · 0 won ⚠
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid rgba(128,128,128,.3);font-weight:800">
                    <td>Total</td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $totals['new_customers'] }}</td>
                    <td></td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $totals['web'] }}</td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums;color:#777">{{ $totals['other'] }}</td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums">£{{ number_format($totals['ad_spend'], 2) }}</td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums;color:#777">{{ $totals['ad_conversions'] ?: '—' }}</td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $cpw !== null ? '£'.number_format($cpw, 2) : '—' }}</td>
                </tr>
            </tfoot>
        </table>
        <p class="hint" style="margin:12px 0 0">A red <strong>Cost / web</strong> flags a month where ad money was spent but <strong>no new website customers</strong> came in — the clearest overspend signal. Ad spend only appears for months whose Google Ads report has been imported.</p>
    </div>
@endsection
