<x-mail::message>
# Your quote — {{ $quote->reference }}

Dear {{ $toName ?: 'Customer' }},

Thank you for your enquiry. Here is your quote for executive chauffeur travel with Central Executive Transfers.

**Journey**
**From:** {{ $quote->pickup_address }}
**To:** {{ $quote->destination_address }}
@if($quote->pickup_at)
**Date &amp; time:** {{ $quote->pickup_at->format('D d M Y, H:i') }}
@endif
**Vehicle:** {{ $quote->vehicleType?->name }}

@if($applyVat)
**Price:** £{{ number_format($net, 2) }} + VAT £{{ number_format($vatAmount, 2) }} = **£{{ number_format($gross, 2) }}** (incl. {{ $ratePercent }}% VAT)
@else
**Price:** **£{{ number_format($gross, 2) }}**
@endif

This is a fixed quote for the journey above. To book, simply reply to this email or call the office and we'll confirm everything and send your driver details ahead of the pickup.

Thank you,

{{ config('cet.company.name') }}<br>
Company No. {{ config('cet.company.number') }} · Operator Licence {{ config('cet.company.operator_licence') }}
</x-mail::message>
