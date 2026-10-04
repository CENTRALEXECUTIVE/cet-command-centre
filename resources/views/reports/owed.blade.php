@extends('layouts.app')
@section('title', 'Money owed')

@php
    $start = $rangeStart->format('Y-m-d');
    $end = $rangeEnd->format('Y-m-d');
    $money = 'font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap';
@endphp

@section('content')
    <h1 class="page-title" style="margin-bottom:2px">💷 Money owed</h1>
    <p class="page-sub">What payroll still owes drivers, and outstanding corporate-account invoices, for jobs that have run in the period.</p>

    <div class="card" style="margin:10px 0;padding:12px 16px">
        <form method="GET" action="{{ route('reports.owed') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div>
                <label class="muted" style="font-size:12px;display:block;margin-bottom:3px">From</label>
                <input type="date" name="start" value="{{ $start }}" style="width:auto">
            </div>
            <div>
                <label class="muted" style="font-size:12px;display:block;margin-bottom:3px">To</label>
                <input type="date" name="end" value="{{ $end }}" style="width:auto">
            </div>
            <button type="submit" class="btn btn-primary" style="padding:8px 16px">Show</button>
        </form>
    </div>

    {{-- Owed to drivers --}}
    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">
            <h2 style="margin:0">Owed to drivers</h2>
            <span style="{{ $money }};font-size:18px;color:#b8860b">£{{ number_format($data['driver_total'], 2) }}</span>
        </div>
        <p class="hint" style="margin:4px 0 10px">Pay earned on jobs that have run but not yet handed over. Mark paid on each booking or in Payroll.</p>
        @if($data['driver_rows']->isEmpty())
            <p class="muted mb-0">Nothing owed to drivers in this period. 🎉</p>
        @else
            <table style="width:100%">
                <thead><tr><th>Driver</th><th style="text-align:right">Jobs</th><th style="text-align:right">Owed</th></tr></thead>
                <tbody>
                    @foreach($data['driver_rows'] as $r)
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td style="text-align:right">{{ $r['jobs'] }}</td>
                            <td style="text-align:right;{{ $money }}">£{{ number_format($r['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Owed by accounts --}}
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">
            <h2 style="margin:0">Account invoices outstanding</h2>
            <span style="{{ $money }};font-size:18px;color:#1f7a44">£{{ number_format($data['account_total'], 2) }}</span>
        </div>
        <p class="hint" style="margin:4px 0 10px">Invoiced (account) jobs that have run and aren't marked paid — invoices to raise or chase.</p>
        @if($data['account_rows']->isEmpty())
            <p class="muted mb-0">No outstanding account invoices in this period.</p>
        @else
            <table style="width:100%">
                <thead><tr><th>Account</th><th style="text-align:right">Jobs</th><th style="text-align:right">Outstanding</th></tr></thead>
                <tbody>
                    @foreach($data['account_rows'] as $r)
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td style="text-align:right">{{ $r['jobs'] }}</td>
                            <td style="text-align:right;{{ $money }}">£{{ number_format($r['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
