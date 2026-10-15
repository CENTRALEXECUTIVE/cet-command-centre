@extends('layouts.app')
@section('title', 'VAT report')

@php
    $s = $start->format('Y-m-d');
    $e = $end->format('Y-m-d');
    $money = 'font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap';
    // Quick quarter presets (UK calendar quarters) for the current year.
    $yr = now()->year;
    $quarters = [
        'Q1 (Jan–Mar)' => [$yr.'-01-01', $yr.'-03-31'],
        'Q2 (Apr–Jun)' => [$yr.'-04-01', $yr.'-06-30'],
        'Q3 (Jul–Sep)' => [$yr.'-07-01', $yr.'-09-30'],
        'Q4 (Oct–Dec)' => [$yr.'-10-01', $yr.'-12-31'],
    ];
@endphp

@section('content')
    <h1 class="page-title" style="margin-bottom:2px">🧾 VAT report</h1>
    <p class="page-sub">Every VAT invoice in the period — net, VAT and gross, and whether the VAT has been received. Use it for the VAT return. (VAT invoices are billed by Central Executive Transfers Ltd.)</p>

    <div class="card" style="margin:10px 0;padding:12px 16px">
        <form method="GET" action="{{ route('reports.vat') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div>
                <label class="muted" style="font-size:12px;display:block;margin-bottom:3px">From</label>
                <input type="date" name="start" value="{{ $s }}" style="width:auto">
            </div>
            <div>
                <label class="muted" style="font-size:12px;display:block;margin-bottom:3px">To</label>
                <input type="date" name="end" value="{{ $e }}" style="width:auto">
            </div>
            <button type="submit" class="btn btn-primary" style="padding:8px 16px">Show</button>
            <a href="{{ route('reports.vat', ['start' => $s, 'end' => $e, 'csv' => 1]) }}" class="btn btn-ghost" style="padding:8px 16px">⬇ CSV</a>
        </form>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
            @foreach($quarters as $label => $range)
                <a href="{{ route('reports.vat', ['start' => $range[0], 'end' => $range[1]]) }}" class="btn btn-ghost" style="padding:5px 10px;font-size:12px">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    {{-- Totals --}}
    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="muted" style="font-size:12px">VAT invoices</div><div style="{{ $money }};font-size:20px">{{ $data['count'] }}</div></div>
            <div><div class="muted" style="font-size:12px">Net total</div><div style="{{ $money }};font-size:20px">£{{ number_format($data['net'], 2) }}</div></div>
            <div><div class="muted" style="font-size:12px">VAT total</div><div style="{{ $money }};font-size:20px;color:var(--accent,#b8860b)">£{{ number_format($data['vat'], 2) }}</div></div>
            <div><div class="muted" style="font-size:12px">Gross total</div><div style="{{ $money }};font-size:20px">£{{ number_format($data['gross'], 2) }}</div></div>
            <div><div class="muted" style="font-size:12px">VAT received</div><div style="{{ $money }};font-size:20px;color:#1f7a44">£{{ number_format($data['vat_received'], 2) }}</div></div>
            <div><div class="muted" style="font-size:12px">VAT outstanding</div><div style="{{ $money }};font-size:20px;color:#b32020">£{{ number_format($data['vat_outstanding'], 2) }}</div></div>
        </div>
    </div>

    <div class="card">
        @if($data['rows']->isEmpty())
            <p class="muted" style="margin:0">No VAT invoices in this period.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--line)">
                            <th style="padding:6px 8px">Date</th>
                            <th style="padding:6px 8px">Ref</th>
                            <th style="padding:6px 8px">Company</th>
                            <th style="padding:6px 8px;text-align:right">Net</th>
                            <th style="padding:6px 8px;text-align:right">VAT</th>
                            <th style="padding:6px 8px;text-align:right">Gross</th>
                            <th style="padding:6px 8px">VAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data['rows'] as $r)
                            <tr style="border-bottom:1px solid var(--line)">
                                <td style="padding:6px 8px;white-space:nowrap">{{ $r['date']?->format('d/m/Y H:i') }}</td>
                                <td style="padding:6px 8px;white-space:nowrap"><a href="{{ route('bookings.show', $r['id']) }}">{{ $r['reference'] }}</a></td>
                                <td style="padding:6px 8px">{{ $r['company'] }}</td>
                                <td style="padding:6px 8px;text-align:right;{{ $money }}">£{{ number_format($r['net'], 2) }}</td>
                                <td style="padding:6px 8px;text-align:right;{{ $money }};color:var(--accent,#b8860b)">£{{ number_format($r['vat'], 2) }}</td>
                                <td style="padding:6px 8px;text-align:right;{{ $money }}">£{{ number_format($r['gross'], 2) }}</td>
                                <td style="padding:6px 8px;white-space:nowrap">
                                    @if($r['paid'])
                                        <span class="badge" style="background:#1f7a44;color:#fff">✓ received</span>
                                    @else
                                        <span class="badge" style="background:#b32020;color:#fff">Outstanding</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
