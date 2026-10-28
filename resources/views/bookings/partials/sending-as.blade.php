{{-- "Sending as": which company + from-address a customer email on this booking
     goes out from (VAT → Ltd, no VAT → PVT LTD), so the office can check it's the
     right one BEFORE pressing send. Mirrors Booking::senderAddress(). --}}
@php
    $saPvt = $booking->billingEntity() === 'chauffeurs';
    $saCompany = $saPvt
        ? (string) config('cet.company_novat.name', 'Central Executive Transfers PVT LTD')
        : (string) config('cet.company.name', 'Central Executive Transfers Ltd');
    $saFrom = $booking->senderAddress();
    $saFallback = $saPvt && ! \App\Models\Setting::get('mail_pvt_enabled', false);
@endphp
<span class="hint sending-as" style="display:inline-block;font-size:12px;margin:{{ $saMargin ?? '0' }}">
    Sending as <strong>{{ $saCompany }}</strong> · {{ $saFrom }}
    @if($saFallback)
        <span style="color:#b32020"> ⚠ PVT sending is off in Settings → Email, so this goes from the main address</span>
    @endif
</span>
