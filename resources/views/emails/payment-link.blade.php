@php $ref = $booking->external_reference ?: $booking->reference; @endphp
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
        <p style="margin:0 0 14px;font-size:15px">Thank you for booking with Central Executive Transfers. To secure your journey
            <strong>{{ $ref }}</strong>, please pay the balance below by card.</p>
    </td></tr>

    <tr><td style="padding:6px 26px 4px" align="center">
        <div style="font-size:28px;font-weight:800;margin:6px 0 14px">£{{ number_format($amount, 2) }}</div>
        <a href="{{ $payUrl }}" style="display:inline-block;background:#12a1c0;color:#fff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:800;font-size:15px">Pay now</a>
        <p style="font-size:12.5px;color:#666;margin:14px 0 0">Secure card payment. If the button doesn’t work, copy this link into your browser:<br>
            <span style="word-break:break-all">{{ $payUrl }}</span></p>
    </td></tr>

    <tr><td style="padding:18px 26px 26px;background:#f6f5f2;color:#555;font-size:12.5px;text-align:center;margin-top:14px">
        <strong style="color:#111">Central Executive Transfers</strong> &nbsp;|&nbsp; Tel: +447405172435<br>
        Email: admin@centralexecutivetransfers.co.uk &nbsp;|&nbsp; https://centralexecutivetransfers.co.uk/
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
