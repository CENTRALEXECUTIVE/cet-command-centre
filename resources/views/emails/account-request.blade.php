@php
    $isCompany = $type === 'company';
    $row = fn ($k, $v) => $v !== null && $v !== ''
        ? '<tr><td style="padding:4px 14px 4px 0;color:#666;vertical-align:top;white-space:nowrap">'.$k.'</td><td style="padding:4px 0;color:#111">'.e($v).'</td></tr>'
        : '';
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f2f1ee;font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden">

    <tr><td style="background:#12558c;color:#fff;padding:16px 26px;font-size:18px;font-weight:700">
        {{ $isCompany ? '🏢 New business account request' : '👤 New personal account' }}
    </td></tr>

    <tr><td style="padding:18px 26px 6px">
        <p style="margin:0 0 6px;font-size:15px">
            @if($isCompany)
                <strong>{{ $name }}</strong> has applied for an invoice/credit account. Review and approve before any credit is extended.
            @else
                <strong>{{ $name }}</strong> has registered a personal account and can manage bookings via My Account.
            @endif
        </p>
    </td></tr>

    <tr><td style="padding:8px 26px 0">
        <h2 style="font-size:16px;margin:0 0 8px">Details</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            @foreach($details as $label => $value){!! $row($label, $value) !!}@endforeach
        </table>
    </td></tr>

    @if($reviewUrl)
    <tr><td style="padding:22px 26px">
        <a href="{{ $reviewUrl }}" style="display:block;background:#FBBA2A;color:#111;text-decoration:none;text-align:center;padding:13px;border-radius:8px;font-weight:800">{{ $isCompany ? 'Review &amp; approve' : 'View customer' }}</a>
    </td></tr>
    @endif

</table>
</td></tr>
</table>
</body>
</html>
