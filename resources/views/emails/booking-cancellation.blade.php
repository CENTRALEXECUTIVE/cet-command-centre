@php
    $ref = $booking->external_reference ?: $booking->reference;
    $row = fn ($k, $v) => $v !== null && $v !== ''
        ? '<tr><td style="padding:4px 14px 4px 0;color:#666;vertical-align:top;white-space:nowrap">'.$k.'</td><td style="padding:4px 0;color:#111">'.e($v).'</td></tr>'
        : '';
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f2f1ee;font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f1ee;padding:20px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden">

    {{-- Brand header --}}
    <tr><td style="padding:22px" align="center">
        <div style="background:#0b0b0b;color:#fff;font-weight:700;letter-spacing:8px;font-size:26px;padding:44px 0;border-radius:8px">CENTRAL</div>
    </td></tr>

    {{-- Cancelled notice --}}
    <tr><td style="padding:22px 26px 0">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fdecec;border:1px solid #f1b7b7;border-radius:10px">
            <tr><td style="padding:16px 18px">
                <div style="font-size:17px;font-weight:800;color:#8f1f1f;margin-bottom:4px">Your booking has been cancelled</div>
                <div style="font-size:14px;color:#7a2a2a">Booking <strong>{{ $ref }}</strong> has been cancelled.</div>
            </td></tr>
        </table>
    </td></tr>

    {{-- Greeting --}}
    <tr><td style="padding:18px 26px 0">
        <p style="margin:0;font-size:15px">Dear {{ $booking->displayName() ?: ($booking->customer?->name ?: 'Customer') }},</p>
        <p style="margin:10px 0 0;font-size:15px">Thank you for letting us know. This email confirms that your booking below has been cancelled.</p>
    </td></tr>

    {{-- Journey --}}
    <tr><td style="padding:14px 26px 0">
        <h2 style="font-size:16px;margin:0 0 8px">Cancelled journey</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Reference:', $ref) !!}
            {!! $row('Date &amp; time:', $booking->pickup_at?->format('d/m/Y H:i')) !!}
            {!! $row('Pickup:', $booking->pickup_address) !!}
            {!! $row('Dropoff:', $booking->destination_address) !!}
            {!! $row('Vehicle:', $booking->vehicleType?->name) !!}
        </table>
    </td></tr>

    {{-- Refund --}}
    <tr><td style="padding:18px 26px 0">
        @if($paid > 0)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef7f0;border:1px solid #cfe6d8;border-radius:10px">
                <tr><td style="padding:14px 16px">
                    <div style="font-size:15px;font-weight:800;color:#1f7a44;margin-bottom:3px">Refund of £{{ number_format($paid, 2) }}</div>
                    <div style="font-size:13.5px;color:#2f5d43">We will process a refund of <strong>£{{ number_format($paid, 2) }}</strong> to your original payment method. Please allow a few working days for it to appear, depending on your bank.</div>
                </td></tr>
            </table>
        @else
            <p style="margin:0;font-size:14px;color:#444">No payment had been taken for this booking, so there is nothing to refund.</p>
        @endif
    </td></tr>

    <tr><td style="padding:18px 26px 4px">
        <p style="margin:0;font-size:14px;color:#444">If you’d like to rebook, or if you believe this cancellation was made in error, just reply to this email or call us and we’ll be glad to help.</p>
    </td></tr>

    {{-- Footer --}}
    <tr><td style="padding:18px 26px 26px;background:#f6f5f2;color:#555;font-size:12.5px;text-align:center">
        <strong style="color:#111">{{ $company['name'] ?? 'Central Executive Transfers' }}</strong> &nbsp;|&nbsp; Tel: +447405172435<br>
        Email: {{ $contactEmail }} &nbsp;|&nbsp; Website: https://centralexecutivetransfers.co.uk/
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
