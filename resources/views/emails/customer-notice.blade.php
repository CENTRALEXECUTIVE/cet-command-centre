<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f2f1ee;font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden">

    <tr><td style="background:#0b0b0b;color:#fff;padding:18px 26px;font-size:17px;font-weight:800;letter-spacing:.04em">CENTRAL EXECUTIVE <span style="color:#FBBA2A">TRANSFERS</span></td></tr>

    <tr><td style="padding:22px 26px 6px">
        <h1 style="font-size:19px;margin:0 0 10px;color:#111">{{ $heading }}</h1>
        <p style="margin:0 0 10px;font-size:15px;color:#222">{{ $lead }}</p>
        @foreach($lines as $line)
            <p style="margin:0 0 8px;font-size:14px;color:#444">{{ $line }}</p>
        @endforeach
    </td></tr>

    <tr><td style="padding:14px 26px 26px">
        <p style="margin:0;font-size:13px;color:#777">
            Central Executive Transfers &bull; Sheffield &bull;
            <a href="tel:+441143998242" style="color:#111;text-decoration:none">0114 399 8242</a>
        </p>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
