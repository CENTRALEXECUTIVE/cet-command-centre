@extends('layouts.app')
@section('title', 'Invoices')

@section('content')
    <h1 class="page-title">Invoices</h1>
    <p class="page-sub">VAT invoices for corporate account bookings.</p>

    <div class="card">
        @if($invoices->isEmpty())
            <p class="muted mb-0">No invoices yet.</p>
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
