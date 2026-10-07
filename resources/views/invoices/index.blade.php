@extends('layouts.app')
@section('title', 'Invoices')

@section('content')
    <h1 class="page-title">Corporate invoices</h1>
    <p class="page-sub">Monthly VAT invoices for your <strong>corporate account clients</strong> (companies billed once a month for all their jobs — JELD-WEN, LB Foster, Forged Solutions, etc.). For a one-off customer use the <strong>receipt on the booking</strong>; for jobs you covered for another operator use <strong>Cover invoices</strong>.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @if(auth()->user()->isAdmin())
        <div class="card" style="margin-bottom:16px">
            <strong>Generate a month's invoices</strong>
            <p class="hint" style="margin:6px 0 10px">Rolls every completed corporate-account booking in the month into one invoice per company. Runs automatically each month — use this to create or re-create a month on demand.</p>
            <form method="POST" action="{{ route('invoices.generate') }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0">
                @csrf
                <input type="month" name="month" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                <button class="btn btn-primary" style="padding:8px 16px">Generate invoices</button>
            </form>
        </div>
    @endif

    <div class="card">
        @if($invoices->isEmpty())
            <p class="muted" style="margin:0">No corporate invoices yet. They appear here once generated — pick a month above and press <strong>Generate invoices</strong>, or wait for the automatic monthly run. If nothing generates, there were no corporate-account bookings that month (one-off and cash jobs aren't billed here).</p>
        @else
            <table>
                <thead><tr><th>Number</th><th>Account</th><th>Period</th><th>Total</th><th>Status</th><th>Due</th></tr></thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}" class="mono">{{ $invoice->invoice_number }}</a></td>
                            <td>{{ $invoice->corporateAccount?->name }}</td>
                            <td>{{ $invoice->period_start?->format('M Y') }}</td>
                            <td>£{{ number_format($invoice->total, 2) }}</td>
                            <td><span class="badge badge-{{ $invoice->status === 'paid' ? 'complete' : ($invoice->status === 'overdue' ? 'cancelled' : 'allocated') }}">{{ ucfirst($invoice->status) }}</span></td>
                            <td>{{ $invoice->due_at?->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $invoices->links() }}</div>
        @endif
    </div>
@endsection
