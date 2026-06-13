@php($inv = $invoice)
<x-mail::message>
# Invoice {{ $inv->invoice_number }}

Dear {{ $inv->corporateAccount?->name }},

Please find your Central Executive Transfers VAT invoice for the period
**{{ $inv->period_start?->format('d M Y') }} – {{ $inv->period_end?->format('d M Y') }}**.

<x-mail::table>
| Description | Cost code | Amount |
|:-----------|:----------|-------:|
@foreach($inv->items as $item)
| {{ \Illuminate\Support\Str::limit($item->description, 60) }} | {{ $item->cost_code ?? '—' }} | £{{ number_format($item->amount, 2) }} |
@endforeach
</x-mail::table>

**Subtotal:** £{{ number_format($inv->subtotal, 2) }}
**VAT ({{ (int) (config('cet.vat_rate') * 100) }}%):** £{{ number_format($inv->vat_amount, 2) }}
**Total due:** £{{ number_format($inv->total, 2) }}

Payment due by **{{ $inv->due_at?->format('d M Y') }}**.

Thank you for your business.

{{ config('cet.company.name') }}<br>
Company No. {{ config('cet.company.number') }} · Operator Licence {{ config('cet.company.operator_licence') }}
</x-mail::message>
