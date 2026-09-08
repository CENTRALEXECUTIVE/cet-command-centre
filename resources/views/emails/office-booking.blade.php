@php
    $ref = $booking->external_reference ?: $booking->reference;
    $gross = $vat['gross'] ?? ($booking->fareGross() ?? 0);
    $paid = ($booking->payment_status ?? null) === 'paid';
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

    <tr><td style="background:#12558c;color:#fff;padding:16px 26px;font-size:18px;font-weight:700">New booking {{ $ref }}</td></tr>

    <tr><td style="padding:18px 26px 6px">
        <p style="margin:0 0 6px;font-size:15px">New booking <strong>{{ $ref }}</strong> has been created.</p>
    </td></tr>

    <tr><td style="padding:8px 26px 0">
        <h2 style="font-size:16px;margin:0 0 8px">Journey</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Date &amp; time:', $booking->pickup_at?->format('d/m/Y H:i')) !!}
            {!! $row('Time zone:', 'UTC+1 London') !!}
            {!! $row('Pickup:', $booking->pickup_address) !!}
            @foreach($booking->viaStops() as $stop){!! $row('Via:', $stop) !!}@endforeach
            {!! $row('Dropoff:', $booking->destination_address) !!}
            {!! $row('Vehicle type:', $booking->vehicleType?->name) !!}
            {!! $row('Passengers:', $booking->passengers) !!}
            {!! $row('Flight:', $booking->displayFlightNumber()) !!}
        </table>
    </td></tr>

    <tr><td style="padding:16px 26px 0">
        <h2 style="font-size:16px;margin:0 0 8px">Customer</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Name:', $booking->displayName() ?: $booking->customer?->name) !!}
            {!! $row('Phone number:', $booking->customer?->phone) !!}
            {!! $row('Email:', $booking->customer?->email) !!}
        </table>
    </td></tr>

    <tr><td style="padding:16px 26px 0">
        <h2 style="font-size:16px;margin:0 0 8px">Reservation</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Reference number:', $ref) !!}
            {!! $row('Booking date:', ($booking->created_at ?? now())->format('d/m/Y H:i')) !!}
            {!! $row('Summary:', 'Journey £'.number_format($gross, 2)) !!}
            {!! $row('Total:', '£'.number_format($gross, 2)) !!}
            <tr><td style="padding:4px 14px 4px 0;color:#666">Payments:</td>
                <td style="padding:4px 0;color:#111">£{{ number_format($gross, 2) }} (Square) —
                    <strong style="color:{{ $paid ? '#1f7a44' : '#b9770a' }}">{{ $paid ? 'Paid' : 'Pending' }}</strong></td></tr>
        </table>
    </td></tr>

    <tr><td style="padding:22px 26px">
        <a href="{{ $viewUrl }}" style="display:block;background:#FBBA2A;color:#111;text-decoration:none;text-align:center;padding:13px;border-radius:8px;font-weight:800">View</a>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
