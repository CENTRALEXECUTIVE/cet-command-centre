@php
    $ref = $booking->external_reference ?: $booking->reference;
    $gross = $vat['gross'] ?? ($booking->fareGross() ?? 0);
    $paid = $booking->fareIsPaid();
    $stage = $stage ?? 'new';
    $isPaid = $stage === 'paid';
    $method = $booking->payment_method?->value ?? null;
    $viewUrl = url('/bookings/'.$booking->id);
    $logo = \Illuminate\Support\Facades\URL::to('/images/cet-logo-wide.png');

    $accent = '#FBBA2A';
    // A card booking that isn't paid yet is INCOMPLETE — held but not confirmed
    // until the customer pays (ETO-style). Cash/account jobs are confirmed on
    // creation; a paid booking is confirmed.
    $awaiting = ! $isPaid && $stage !== 'paid' && $method === 'card';

    if ($isPaid) {
        $chipText = 'PAYMENT RECEIVED'; $chipBg = '#1f7a44';
        $heading = 'Payment received'; $lead = 'Payment has been received — this booking is now <strong>confirmed</strong>.';
    } elseif ($awaiting) {
        $chipText = 'INCOMPLETE · UNPAID'; $chipBg = '#b9770a';
        $heading = 'Booking received — awaiting payment';
        $lead = 'This booking is <strong>held but not yet confirmed</strong> — the customer still needs to pay. You\'ll get a second email the moment payment is made.';
    } else {
        $chipText = 'NEW BOOKING'; $chipBg = '#12558c';
        $heading = 'New booking'; $lead = 'A new booking has been created and added to the Command Centre.';
    }

    // A clean key/value row.
    $row = fn ($k, $v) => $v !== null && $v !== ''
        ? '<tr><td style="padding:7px 16px 7px 0;color:#8a8a8a;vertical-align:top;white-space:nowrap;font-size:13px">'.$k.'</td>'
          .'<td style="padding:7px 0;color:#141414;font-size:14px;font-weight:600">'.e($v).'</td></tr>'
        : '';
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#eceae5;font-family:'Segoe UI',Helvetica,Arial,sans-serif;color:#141414;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 18px 50px -24px rgba(0,0,0,.4)">

    {{-- Branded dark masthead with the real logo --}}
    <tr><td style="background:#0d0d0d;padding:22px 28px 20px;border-bottom:3px solid {{ $accent }}">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle"><img src="{{ $logo }}" alt="Central Executive Transfers" height="26" style="height:26px;display:block"></td>
            <td align="right" style="vertical-align:middle">
                <span style="display:inline-block;background:{{ $chipBg }};color:#fff;font-size:11px;font-weight:700;letter-spacing:.09em;padding:5px 11px;border-radius:999px">{{ $chipText }}</span>
            </td>
        </tr></table>
    </td></tr>

    {{-- Reference + lead --}}
    <tr><td style="padding:24px 28px 4px">
        <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:{{ $accent }};font-weight:700">Booking {{ $ref }}</div>
        <div style="font-size:22px;font-weight:800;letter-spacing:-.01em;margin:4px 0 6px">{{ $heading }}</div>
        <p style="margin:0;color:#6c6c6c;font-size:14px">{!! $lead !!}</p>
    </td></tr>

    {{-- Total band --}}
    <tr><td style="padding:16px 28px 0">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf9f6;border:1px solid #ece9e2;border-radius:12px">
            <tr>
                <td style="padding:14px 18px">
                    <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#9a9a9a;font-weight:700">Total fare</div>
                    <div style="font-size:26px;font-weight:800;color:#141414;margin-top:2px">£{{ number_format($gross, 2) }}</div>
                </td>
                <td align="right" style="padding:14px 18px">
                    @if($method === 'account')
                        <span style="display:inline-block;background:#eef3f8;color:#12558c;font-size:12px;font-weight:700;padding:7px 13px;border-radius:999px">On account · invoiced</span>
                    @elseif($method === 'cash')
                        <span style="display:inline-block;background:#fef3e2;color:#b9770a;font-size:12px;font-weight:700;padding:7px 13px;border-radius:999px">Cash to driver</span>
                    @else
                        <span style="display:inline-block;background:{{ $paid ? '#eaf6ef' : '#fef3e2' }};color:{{ $paid ? '#1f7a44' : '#b9770a' }};font-size:12px;font-weight:700;padding:7px 13px;border-radius:999px">Card · {{ $paid ? 'Paid' : 'Pending' }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </td></tr>

    {{-- Journey --}}
    <tr><td style="padding:22px 28px 0">
        <div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#141414;font-weight:800;border-bottom:2px solid #f0ede6;padding-bottom:8px;margin-bottom:6px">Journey</div>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
            {!! $row('Pick-up', $booking->pickup_at?->timezone(config('app.timezone'))->format('D d M Y · H:i')) !!}
            {!! $row('From', $booking->pickup_address) !!}
            @foreach($booking->viaStops() as $stop){!! $row('Via', $stop) !!}@endforeach
            {!! $row('To', $booking->destination_address) !!}
            {!! $row('Vehicle', $booking->vehicleType?->name) !!}
            {!! $row('Passengers', $booking->passengers) !!}
            {!! $row('Luggage', $booking->luggageBreakdown()) !!}
            {!! $row('Flight', $booking->displayFlightNumber()) !!}
            @if($booking->isMeetGreet()){!! $row('Meet &amp; greet', $booking->meetGreetLabel() ?: 'Yes') !!}@endif
            {!! $row('Child seats', $booking->displayChildSeats()) !!}
        </table>
    </td></tr>

    {{-- Customer --}}
    <tr><td style="padding:20px 28px 0">
        <div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#141414;font-weight:800;border-bottom:2px solid #f0ede6;padding-bottom:8px;margin-bottom:6px">Customer</div>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
            {!! $row('Name', $booking->displayName() ?: $booking->customer?->name) !!}
            {!! $row('Phone', \App\Support\Phone::display($booking->customer?->phone)) !!}
            {!! $row('Email', $booking->customer?->email) !!}
            {!! $row('Reference', $ref) !!}
            {!! $row('Booked', ($booking->created_at ?? now())->timezone(config('app.timezone'))->format('d/m/Y · H:i')) !!}
        </table>
    </td></tr>

    {{-- Payment breakdown — total / paid / remaining + a line per payment --}}
    <tr><td style="padding:20px 28px 0">
        <div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#141414;font-weight:800;border-bottom:2px solid #f0ede6;padding-bottom:8px;margin-bottom:8px">Payment</div>
        @include('emails._payments')
    </td></tr>

    {{-- Notes (office / customer / calendar) — highlighted, because they matter --}}
    @php $notes = $booking->driverReadNotes(); @endphp
    @if(filled($notes))
    <tr><td style="padding:20px 28px 0">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff8e8;border:1px solid #f3e4bc;border-left:4px solid {{ $accent }};border-radius:10px">
            <tr><td style="padding:13px 16px">
                <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#9a7a1a;font-weight:800;margin-bottom:4px">📝 Notes</div>
                <div style="font-size:14px;color:#3d3416;white-space:pre-line">{{ $notes }}</div>
            </td></tr>
        </table>
    </td></tr>
    @endif

    {{-- Pay now — for an unpaid card booking, so the office can take payment over
         the phone, or copy the link to send to the customer. --}}
    @if(!empty($payUrl))
    <tr><td style="padding:22px 28px 0">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f7f2;border:1px solid #cfe6d8;border-radius:12px">
            <tr><td style="padding:14px 16px 12px">
                <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#1f7a44;font-weight:800;margin-bottom:8px">💳 Take payment · £{{ number_format((float) ($payAmount ?? $booking->amountPayable()), 2) }}</div>
                <a href="{{ $payUrl }}" style="display:block;background:#1f7a44;color:#fff;text-decoration:none;text-align:center;padding:12px;border-radius:9px;font-weight:800;font-size:15px">Pay now →</a>
                <div style="font-size:11.5px;color:#6b7a70;margin-top:9px;line-height:1.5">Click to take the card payment over the phone — or copy this link and send it to the customer:</div>
                <div style="font-size:11px;color:#3a4a40;word-break:break-all;background:#fff;border:1px solid #d7e6dd;border-radius:7px;padding:8px 10px;margin-top:6px;font-family:monospace">{{ $payUrl }}</div>
            </td></tr>
        </table>
    </td></tr>
    @endif

    {{-- CTA --}}
    <tr><td style="padding:24px 28px 10px">
        <a href="{{ $viewUrl }}" style="display:block;background:{{ $accent }};color:#111;text-decoration:none;text-align:center;padding:14px;border-radius:10px;font-weight:800;font-size:15px">Open in Command Centre →</a>
    </td></tr>

    <tr><td style="padding:10px 28px 24px;text-align:center">
        <div style="color:#9a9a9a;font-size:12px;line-height:1.7">
            <strong style="color:#444">{{ $booking->billingEntity() === 'chauffeurs' ? config('cet.company_novat.name', 'Central Executive Transfers PVT LTD') : 'Central Executive Transfers Ltd' }}</strong><br>
            07405 172435
        </div>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
