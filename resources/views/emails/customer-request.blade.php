@php
    $ref = $booking->external_reference ?: $booking->reference;
    $what = $requestType === 'cancel' ? 'CANCELLATION' : 'CHANGE';
    $accent = $requestType === 'cancel' ? '#b3261e' : '#b9770a';
    $row = fn ($k, $v) => $v !== null && $v !== ''
        ? '<tr><td style="padding:4px 14px 4px 0;color:#666;vertical-align:top;white-space:nowrap">'.$k.'</td><td style="padding:4px 0;color:#111">'.e($v).'</td></tr>'
        : '';
    $viewUrl = url('/bookings/'.$booking->id);
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f2f1ee;font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden">

    <tr><td style="background:{{ $accent }};color:#fff;padding:16px 26px;font-size:18px;font-weight:700">Customer {{ $what }} request — {{ $ref }}</td></tr>

    <tr><td style="padding:18px 26px 6px">
        <p style="margin:0 0 6px;font-size:15px">
            <strong>{{ $booking->displayName() ?: $booking->customer?->name }}</strong> has requested a <strong>{{ strtolower($what) }}</strong> on this booking. Please action it in the app — nothing has been changed automatically.
        </p>
    </td></tr>

    @if($message)
    <tr><td style="padding:6px 26px 0">
        <div style="background:#f6f5f2;border-left:3px solid {{ $accent }};padding:10px 14px;border-radius:6px;font-size:14px;color:#333">“{{ $message }}”</div>
    </td></tr>
    @endif

    <tr><td style="padding:14px 26px 0">
        <h2 style="font-size:16px;margin:0 0 8px">Booking</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Reference:', $ref) !!}
            {!! $row('Pickup:', $booking->pickup_at?->format('D d M Y, H:i')) !!}
            {!! $row('From:', $booking->pickup_address) !!}
            {!! $row('To:', $booking->destination_address) !!}
            {!! $row('Customer:', $booking->customer?->name) !!}
            {!! $row('Phone:', $booking->customer?->phone) !!}
        </table>
    </td></tr>

    <tr><td style="padding:22px 26px">
        <a href="{{ $viewUrl }}" style="display:block;background:#FBBA2A;color:#111;text-decoration:none;text-align:center;padding:13px;border-radius:8px;font-weight:800">Open booking</a>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
