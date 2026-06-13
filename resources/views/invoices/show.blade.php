@extends('layouts.app')
@section('title', 'Invoice ' . $invoice->invoice_number)

@section('content')
    <h1 class="page-title">Invoice <span class="mono">{{ $invoice->invoice_number }}</span></h1>
    <p class="page-sub">{{ $invoice->corporateAccount?->name }} ·
        {{ $invoice->period_start?->format('d M Y') }} – {{ $invoice->period_end?->format('d M Y') }}</p>

    <div class="card">
        <table>
            <thead><tr><th>Booking</th><th>Cost code</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach($invoice->items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td>{{ $item->cost_code ?? '—' }}</td>
                        <td class="right">£{{ number_format($item->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table style="margin-top:16px;max-width:320px;margin-left:auto">
            <tr><th>Subtotal</th><td class="right">£{{ number_format($invoice->subtotal, 2) }}</td></tr>
            <tr><th>VAT ({{ (int) (config('cet.vat_rate') * 100) }}%)</th><td class="right">£{{ number_format($invoice->vat_amount, 2) }}</td></tr>
            <tr><th>Total</th><td class="right"><strong>£{{ number_format($invoice->total, 2) }}</strong></td></tr>
        </table>
    </div>

    <a href="{{ route('invoices.index') }}" class="btn btn-ghost">← All invoices</a>
@endsection
