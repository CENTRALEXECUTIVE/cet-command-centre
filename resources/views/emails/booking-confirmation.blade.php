@php
    $ref = $booking->external_reference ?: $booking->reference;
    $gross = $vat['gross'] ?? ($booking->fareGross() ?? 0);
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

    {{-- Logo --}}
    <tr><td style="padding:22px" align="center">
        <div style="background:#0b0b0b;color:#fff;font-weight:700;letter-spacing:3px;font-size:22px;padding:34px 0;border-radius:8px">C=NTRAL</div>
    </td></tr>

    <tr><td style="padding:0 26px 8px">
        <p style="margin:0 0 14px;font-size:15px"><strong>Booking Confirmation:</strong> Thank you for booking with Central Executive Transfers. Your reservation number is <strong>{{ $ref }}</strong>.</p>
    </td></tr>

    {{-- Journey --}}
    <tr><td style="padding:8px 26px 0">
        <h2 style="font-size:17px;margin:0 0 8px">Journey</h2>
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

    {{-- Customer --}}
    <tr><td style="padding:18px 26px 0">
        <h2 style="font-size:17px;margin:0 0 8px">Customer</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Name:', $booking->displayName() ?: $booking->customer?->name) !!}
            {!! $row('Phone number:', $booking->customer?->phone) !!}
            {!! $row('Email:', $booking->customer?->email) !!}
        </table>
    </td></tr>

    {{-- Reservation --}}
    <tr><td style="padding:18px 26px 0">
        <h2 style="font-size:17px;margin:0 0 8px">Reservation</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Reference number:', $ref) !!}
            {!! $row('Booking date:', ($booking->created_at ?? now())->format('d/m/Y H:i')) !!}
            {!! $row('Summary:', 'Journey £'.number_format($gross, 2)) !!}
            {!! $row('Total:', '£'.number_format($gross, 2)) !!}
            @if($vat && ($vat['vat'] ?? 0) > 0)
                {!! $row('Includes VAT:', '£'.number_format($vat['vat'], 2).' (net £'.number_format($vat['net'], 2).')') !!}
            @endif
            <tr><td style="padding:4px 14px 4px 0;color:#666;vertical-align:top">Payments:</td>
                <td style="padding:4px 0;color:#111">
                    £{{ number_format($gross, 2) }} (Square) —
                    <strong style="color:{{ $paid ? '#1f7a44' : '#b9770a' }}">{{ $paid ? 'Paid' : 'Pending' }}</strong>
                    @if(! $paid && $payUrl)
                        <br><a href="{{ $payUrl }}" style="display:inline-block;margin-top:8px;background:#12a1c0;color:#fff;text-decoration:none;padding:9px 18px;border-radius:6px;font-weight:700">Pay now</a>
                    @endif
                </td></tr>
            {!! $row('Amount due:', '£'.number_format($paid ? 0 : $gross, 2)) !!}
        </table>
    </td></tr>

    {{-- Important info --}}
    <tr><td style="padding:22px 26px 8px">
        <h3 style="font-size:14px;margin:0 0 8px">Important information for your reservation:</h3>
        <ul style="font-size:13px;color:#333;margin:0;padding-left:18px">
            <li>If you wish to cancel, make changes, or pay by debit/credit card, please contact us.</li>
            <li>Bookings made directly with the driver are illegal and, in case of an accident, you will not be covered by insurance.</li>
            <li>For airport pickups, the driver will be waiting in the terminal (meeting point) with a sign showing your name (please switch on your mobile once you land).</li>
            <li>For address pickups, the driver will be waiting in front of the door. If there are parking restrictions, they will be waiting near the pickup location (the closest spot).</li>
        </ul>
    </td></tr>

    {{-- Tip the driver --}}
    @if(!empty($tipUrl))
    <tr><td style="padding:6px 26px 4px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffdf5;border:1px solid #FBBA2A;border-radius:10px">
            <tr><td style="padding:16px 18px;text-align:center">
                <div style="font-size:15px;font-weight:700;margin-bottom:4px">Look after your driver 🌟</div>
                <div style="font-size:13.5px;color:#555;margin-bottom:12px">Happy with your journey? You can leave a tip securely any time — <strong>100% goes to your driver</strong>.</div>
                <a href="{{ $tipUrl }}" style="display:inline-block;background:#0b0b0b;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:800;font-size:14px">Add a tip</a>
            </td></tr>
        </table>
    </td></tr>
    @endif

    {{-- Footer --}}
    <tr><td style="padding:18px 26px 26px;background:#f6f5f2;color:#555;font-size:12.5px;text-align:center">
        <strong style="color:#111">Central Executive Transfers</strong> &nbsp;|&nbsp; Tel: +447405172435<br>
        Email: admin@centralexecutivetransfers.co.uk &nbsp;|&nbsp; Website: https://centralexecutivetransfers.co.uk/
        @if($vatNumber)<br>VAT No: {{ $vatNumber }}@endif
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
