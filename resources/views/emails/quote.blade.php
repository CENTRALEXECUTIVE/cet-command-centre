<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your quote</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f2;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your Central Executive Transfers quote {{ $quote->reference }} — £{{ number_format($gross, 2) }}.</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f2;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;font-family:Helvetica,Arial,sans-serif;color:#111111;">

        {{-- Header --}}
        <tr>
          <td style="background:#111111;padding:26px 32px;border-top:4px solid #FBBA2A;">
            <div style="font-size:19px;font-weight:bold;letter-spacing:.5px;color:#ffffff;">CENTRAL <span style="color:#FBBA2A;">EXECUTIVE</span> TRANSFERS</div>
            <div style="font-size:11px;letter-spacing:1.5px;color:#bdbdbd;margin-top:4px;text-transform:uppercase;">Sheffield · Executive Chauffeurs</div>
          </td>
        </tr>

        {{-- Body --}}
        <tr>
          <td style="padding:30px 32px 10px;">
            <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#8a8a8a;font-weight:bold;">Your quote</div>
            <div style="font-size:24px;font-weight:bold;color:#111111;margin:4px 0 2px;">{{ $quote->reference }}</div>
            <p style="font-size:15px;color:#111111;line-height:1.6;margin:18px 0 0;">Dear {{ $toName ?: 'Customer' }},</p>
            <p style="font-size:15px;color:#111111;line-height:1.6;margin:10px 0 0;">Thank you for your enquiry. Here is your price for executive chauffeur travel with Central Executive Transfers.</p>
          </td>
        </tr>

        {{-- Journey --}}
        <tr>
          <td style="padding:22px 32px 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #ececec;border-radius:10px;">
              <tr><td style="padding:14px 16px;border-bottom:1px solid #f1f1f1;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a8a8a;font-weight:bold;">From</div>
                <div style="font-size:14px;color:#111111;margin-top:3px;">{{ $quote->pickup_address }}</div>
              </td></tr>
              <tr><td style="padding:14px 16px;border-bottom:1px solid #f1f1f1;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a8a8a;font-weight:bold;">To</div>
                <div style="font-size:14px;color:#111111;margin-top:3px;">{{ $quote->destination_address }}</div>
              </td></tr>
              @if($quote->pickup_at)
              <tr><td style="padding:14px 16px;border-bottom:1px solid #f1f1f1;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a8a8a;font-weight:bold;">Date &amp; time</div>
                <div style="font-size:14px;color:#111111;margin-top:3px;">{{ $quote->pickup_at->format('l d F Y, H:i') }}</div>
              </td></tr>
              @endif
              <tr><td style="padding:14px 16px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#8a8a8a;font-weight:bold;">Vehicle</div>
                <div style="font-size:14px;color:#111111;margin-top:3px;">{{ $quote->vehicleType?->name }}</div>
              </td></tr>
            </table>
          </td>
        </tr>

        {{-- Price --}}
        <tr>
          <td style="padding:20px 32px 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#111111;border-radius:12px;">
              <tr>
                <td style="padding:20px 22px;">
                  <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#FBBA2A;font-weight:bold;">Fixed price</div>
                  <div style="font-size:34px;font-weight:bold;color:#ffffff;margin-top:4px;">£{{ number_format($gross, 2) }}</div>
                  @if($applyVat)
                    <div style="font-size:12px;color:#c7c7c7;margin-top:4px;">£{{ number_format($net, 2) }} + £{{ number_format($vatAmount, 2) }} VAT ({{ $ratePercent }}%) · VAT invoice provided</div>
                  @else
                    <div style="font-size:12px;color:#c7c7c7;margin-top:4px;">All-inclusive · no hidden extras</div>
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Next steps --}}
        <tr>
          <td style="padding:24px 32px 6px;">
            <div style="font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#8a8a8a;font-weight:bold;margin-bottom:8px;">To book</div>
            <p style="font-size:14px;color:#111111;line-height:1.7;margin:0;">
              Just reply to this email or call the office to confirm. We'll then send you a <strong>secure card payment link</strong> to pay, hold your booking, and send your <strong>driver's name and details</strong> shortly before pickup. Your price is fixed for the journey above.
            </p>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="padding:22px 32px 30px;">
            <div style="border-top:1px solid #ececec;padding-top:16px;font-size:11px;color:#8a8a8a;line-height:1.7;">
              <strong style="color:#333333;">{{ config('cet.company.name') }}</strong><br>
              Company No. {{ config('cet.company.number') }} · Operator Licence {{ config('cet.company.operator_licence') }}@if(trim((string) \App\Support\InvoiceProfile::company()['vat_number'])) · VAT {{ \App\Support\InvoiceProfile::company()['vat_number'] }}@endif
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
