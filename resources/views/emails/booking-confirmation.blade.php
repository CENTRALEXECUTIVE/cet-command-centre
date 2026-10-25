@php
    $ref = $booking->external_reference ?: $booking->reference;
    $gross = $vat['gross'] ?? ($booking->fareGross() ?? 0);
    $isCash = $booking->isCashCollectJob();
    $isAccount = ($booking->payment_method?->value ?? null) === 'account';
    // A card booking that isn't paid yet is ON HOLD — reserved but NOT confirmed
    // until the customer pays. Cash/account jobs are confirmed on creation; a paid
    // booking is confirmed. When there's no pay link it's a price-on-request enquiry.
    $awaitingPayment = ! $paid && ! $isCash && ! $isAccount && ! empty($payUrl);
    $isEnquiry = ! $paid && ! $isCash && ! $isAccount && empty($payUrl);
    $contactEmail = $company['email'] ?? 'admin@centralexecutivetransfers.co.uk';
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

    {{-- On hold (unpaid card) vs confirmed (paid / cash / account) vs enquiry --}}
    @if($awaitingPayment)
        <tr><td style="padding:22px 26px 0">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff6e5;border:1px solid #f1d79a;border-radius:10px">
                <tr><td style="padding:16px 18px">
                    <div style="font-size:17px;font-weight:800;color:#8a5a00;margin-bottom:4px">⏳ Your booking is on hold</div>
                    <div style="font-size:14px;color:#6b5420">We've reserved your journey (ref <strong>{{ $ref }}</strong>), but it is <strong>not confirmed yet</strong>. Please complete payment below to lock it in — your booking is only confirmed once payment is made.</div>
                </td></tr>
            </table>
        </td></tr>
    @elseif($isEnquiry)
        <tr><td style="padding:22px 26px 0">
            <p style="margin:0;font-size:15px"><strong>Request received.</strong> Thanks — we've got your journey details (ref <strong>{{ $ref }}</strong>). Our office will confirm the price and get back to you shortly to take payment and lock it in.</p>
        </td></tr>
    @else
        <tr><td style="padding:22px 26px 0">
            <p style="margin:0;font-size:15px"><strong>Booking Confirmation:</strong> Thank you for booking with Central Executive Transfers. Your reservation number is <strong>{{ $ref }}</strong>.</p>
        </td></tr>
    @endif

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
            @php $landing = $booking->meta['flight_landing_at'] ?? null; @endphp
            @if($landing){!! $row('Flight lands:', \Illuminate\Support\Carbon::parse($landing)->format('d/m/Y H:i')) !!}@endif
            @if(!empty($booking->meta['meet_greet'])){!! $row('Meet &amp; greet:', 'Yes — driver waits in arrivals with a name board') !!}@endif
        </table>
    </td></tr>

    {{-- Customer --}}
    <tr><td style="padding:18px 26px 0">
        <h2 style="font-size:17px;margin:0 0 8px">Customer</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Name:', $booking->displayName() ?: $booking->customer?->name) !!}
            {!! $row('Phone number:', \App\Support\Phone::display($booking->customer?->phone)) !!}
            {!! $row('Email:', $booking->customer?->email) !!}
        </table>
    </td></tr>

    {{-- Reservation --}}
    <tr><td style="padding:18px 26px 0">
        <h2 style="font-size:17px;margin:0 0 8px">Reservation</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Reference number:', $ref) !!}
            {!! $row('Booking date:', ($booking->created_at ?? now())->format('d/m/Y H:i')) !!}
        </table>

        {{-- Payment: what they've just paid + total / paid / remaining, with a line per payment --}}
        <div style="margin-top:14px">
            @if($paid)
                @php $lastPaid = $booking->payments->where('status', 'paid')->sortByDesc('paid_at')->first(); @endphp
                @if($lastPaid)
                    <p style="margin:0 0 10px;font-size:15px;color:#1f7a44;font-weight:700">&#10003; Payment received: £{{ number_format((float) $lastPaid->amount, 2) }}{{ $lastPaid->method === 'card' ? ' by card' : '' }}</p>
                @endif
            @elseif($isCash)
                <p style="margin:0 0 10px;font-size:15px;color:#b9770a;font-weight:700">Pay the driver on the day (cash)</p>
            @elseif($isAccount)
                <p style="margin:0 0 10px;font-size:15px;color:#12558c;font-weight:700">Billed to your business account</p>
            @else
                <p style="margin:0 0 10px;font-size:15px;color:#b9770a;font-weight:700">Awaiting payment — booking on hold</p>
            @endif
            @include('emails._payments')
            @if($booking->vatInvoiceRequested() && $vat && ($vat['vat'] ?? 0) > 0)
                <p style="margin:8px 0 0;font-size:13px;color:#666">Includes VAT £{{ number_format($vat['vat'], 2) }} (net £{{ number_format($vat['net'], 2) }}).</p>
            @endif
            @if(! $paid && ! $isCash && ! $isAccount && $payUrl)
                <p style="margin:12px 0 0"><a href="{{ $payUrl }}" style="display:inline-block;background:#1f7a44;color:#fff;text-decoration:none;padding:11px 22px;border-radius:7px;font-weight:800">Complete payment &rarr;</a></p>
                @if($tripPayTotal ?? null)
                    <p style="margin:7px 0 0;font-size:12.5px;color:#666">This secures your <strong>whole return trip</strong> — the payment covers both the outbound and the return (£{{ number_format($tripPayTotal, 2) }} total). You only pay once.</p>
                @endif
            @endif
        </div>
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
        <strong style="color:#111">{{ $company['name'] ?? 'Central Executive Transfers' }}</strong> &nbsp;|&nbsp; Tel: +447405172435<br>
        Email: {{ $contactEmail }} &nbsp;|&nbsp; Website: https://centralexecutivetransfers.co.uk/
        @if($vatNumber)<br>VAT No: {{ $vatNumber }}@endif
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
