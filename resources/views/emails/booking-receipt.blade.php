<x-mail::message>
# {{ $isVat ? 'VAT Invoice' : 'Receipt' }} — {{ $booking->reference }}

Dear {{ $booking->displayName() }},

Please find attached your {{ $isVat ? 'VAT invoice' : 'receipt' }} from Central
Executive Transfers for your journey on
**{{ $booking->pickup_at?->format('d M Y') ?? '' }}**.

**From:** {{ $booking->pickup_address }}
**To:** {{ $booking->destination_address }}
@if($booking->fareGross() !== null)
**Total:** £{{ number_format((float) $booking->fareGross(), 2) }}
@endif

Thank you for travelling with us.

{{ config('cet.company.name') }}<br>
Company No. {{ config('cet.company.number') }} · Operator Licence {{ config('cet.company.operator_licence') }}
</x-mail::message>
