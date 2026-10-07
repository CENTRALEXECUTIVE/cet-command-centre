@php
    $isCover = $isCover ?? false;
    $cover = $booking->coverFor();
    $billedTo = $isCover && $cover ? $cover['name'] : $booking->displayName();
    $total = $isCover ? $booking->coverForAmount() : $booking->fareGross();
    $kind = $isCover ? 'invoice' : ($isVat ? 'VAT invoice' : 'receipt');
@endphp
<x-mail::message>
# {{ ucfirst($kind) }} — {{ $booking->reference }}

Dear {{ $billedTo }},

@if($isCover)
Please find attached our invoice for the journey Central Executive Transfers
covered on your behalf on **{{ $booking->pickup_at?->format('d M Y') ?? '' }}**.
@else
Please find attached your {{ $kind }} from Central Executive Transfers for your
journey on **{{ $booking->pickup_at?->format('d M Y') ?? '' }}**.
@endif

**From:** {{ $booking->pickup_address }}
**To:** {{ $booking->destination_address }}
@if($total !== null)
**Total:** £{{ number_format((float) $total, 2) }}
@endif

@if($isCover)
Thank you for the work.
@else
Thank you for travelling with us.
@endif

{{ config('cet.company.name') }}<br>
Company No. {{ config('cet.company.number') }} · Operator Licence {{ config('cet.company.operator_licence') }}
</x-mail::message>
