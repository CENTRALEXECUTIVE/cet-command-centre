@php
    $ref = $booking->external_reference ?: $booking->reference;
    $tz = config('app.timezone');
    $gbp = fn ($v) => '£'.number_format((float) $v, 2);
    $isDeposit = $remaining > 0.009;
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f2f1ee;font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f1ee;padding:20px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden">

    <tr><td style="padding:22px" align="center">
        <div style="background:#0b0b0b;color:#fff;font-weight:700;letter-spacing:8px;font-size:26px;padding:44px 0;border-radius:8px">CENTRAL</div>
    </td></tr>

    <tr><td style="padding:0 26px 8px">
        <p style="margin:0 0 14px;font-size:15px">Thank you for booking with Central Executive Transfers.
            @if($isDeposit)
                To secure your journey <strong>{{ $ref }}</strong>, please pay a <strong>{{ $gbp($amount) }} deposit</strong> by card.
            @elseif($paidBefore)
                To complete payment for your journey <strong>{{ $ref }}</strong>, please pay the balance below by card.
            @else
                To secure your journey <strong>{{ $ref }}</strong>, please pay for it below by card.
            @endif
        </p>
    </td></tr>

    <tr><td style="padding:6px 26px 4px" align="center">
        <div style="font-size:13px;color:#666;text-transform:uppercase;letter-spacing:1px">{{ $isDeposit ? 'Deposit' : ($paidBefore ? 'Balance' : 'Amount') }}</div>
        <div style="font-size:28px;font-weight:800;margin:2px 0 14px">{{ $gbp($amount) }}</div>
        <a href="{{ $payUrl }}" style="display:inline-block;background:#12a1c0;color:#fff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:800;font-size:15px">Pay now</a>
        @if($isDeposit)
            <p style="font-size:14px;margin:14px 0 0">
                @if($cashRest)
                    The remaining <strong>{{ $gbp($remaining) }}</strong> is paid in cash to your driver on the day.
                @else
                    The remaining <strong>{{ $gbp($remaining) }}</strong> is due before your journey. We’ll send you a link for it.
                @endif
            </p>
        @endif
        <p style="font-size:12.5px;color:#666;margin:14px 0 0">Secure card payment. If the button doesn’t work, copy this link into your browser:<br>
            <span style="word-break:break-all">{{ $payUrl }}</span></p>
    </td></tr>

    <tr><td style="padding:18px 26px 6px">
        <div style="border:1px solid #e6e3dc;border-radius:10px;padding:12px 14px">
            <div style="font-weight:700;font-size:14px;margin-bottom:6px">Your journey{{ $legs->count() > 1 ? 's' : '' }}</div>
            @foreach($legs as $i => $leg)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13.5px;{{ $i > 0 ? 'border-top:1px solid #eee;margin-top:8px;padding-top:8px' : '' }}">
                    @if($legs->count() > 1)
                        <tr><td colspan="2" style="font-weight:700;padding:2px 0">{{ $i === 0 ? 'Outbound' : 'Return' }}</td></tr>
                    @endif
                    <tr><td style="color:#666;width:90px;padding:2px 0;vertical-align:top">Pick-up</td><td style="padding:2px 0">{{ $leg->pickup_at?->timezone($tz)->format('D j M Y, H:i') ?? 'To be confirmed' }}</td></tr>
                    <tr><td style="color:#666;padding:2px 0;vertical-align:top">From</td><td style="padding:2px 0">{{ $leg->displayPickupAddress() ?? $leg->pickup_address }}</td></tr>
                    <tr><td style="color:#666;padding:2px 0;vertical-align:top">To</td><td style="padding:2px 0">{{ $leg->displayDropoffAddress() ?? $leg->destination_address }}</td></tr>
                    @if($leg->displayVehicleType())
                        <tr><td style="color:#666;padding:2px 0;vertical-align:top">Vehicle</td><td style="padding:2px 0">{{ $leg->vehicleLabel($leg->displayVehicleType()) }}</td></tr>
                    @endif
                </table>
            @endforeach
        </div>
    </td></tr>

    <tr><td style="padding:18px 26px 26px;background:#f6f5f2;color:#555;font-size:12.5px;text-align:center;margin-top:14px">
        <strong style="color:#111">Central Executive Transfers</strong> &nbsp;|&nbsp; Tel: +44 7405 172435<br>
        Email: admin@centralexecutivetransfers.co.uk &nbsp;|&nbsp; https://centralexecutivetransfers.co.uk/
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
