@component('mail::message')
# Thank you for your booking

Hi {{ \Illuminate\Support\Str::before($booking->displayName() ?: ($booking->customer?->name ?? 'there'), ' ') }},

We’ve received your booking request and our office will confirm the final details shortly. Here’s what we have:

@component('mail::panel')
**Reference:** {{ $booking->reference }}
**Date & time:** {{ $booking->pickup_at?->format('D d M Y, H:i') }}
**Pickup:** {{ $booking->pickup_address }}
**Drop-off:** {{ $booking->destination_address }}
**Vehicle:** {{ $booking->vehicleType?->name }}
**Passengers:** {{ $booking->passengers }}
@if($booking->flight_number)**Flight:** {{ $booking->flight_number }}@endif
@if($booking->fareAmount())**Price:** £{{ number_format($booking->fareAmount(), 2) }}@endif
@endcomponent

If anything looks wrong, just reply to this email or call the office and we’ll sort it out.

Thanks,
**Central Executive Transfers**
@endcomponent
