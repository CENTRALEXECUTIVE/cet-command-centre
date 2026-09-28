<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f4f5;font-family:Inter,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px">
        <div style="background:#0b0b0c;color:#fff;border-radius:14px 14px 0 0;padding:20px 22px">
            <div style="font-weight:800;letter-spacing:1.5px;font-size:14px">CENTRAL <span style="color:#FBBA2A">EXECUTIVE</span> TRANSFERS</div>
        </div>
        <div style="background:#fff;border:1px solid #e6e6e6;border-top:0;border-radius:0 0 14px 14px;padding:22px">
            <h1 style="font-size:19px;margin:0 0 10px">Reset your password</h1>
            <p style="font-size:15px;line-height:1.5;color:#333;margin:0 0 8px">
                Hi{{ $name ? ' '.\Illuminate\Support\Str::of($name)->before(' ') : '' }},
            </p>
            <p style="font-size:15px;line-height:1.5;color:#333;margin:0 0 18px">
                We received a request to reset the password for your Central Executive Transfers account.
                Tap the button below to choose a new one. This link expires in <strong>1 hour</strong>.
            </p>
            <p style="text-align:center;margin:0 0 18px">
                <a href="{{ $link }}" style="display:inline-block;background:#FBBA2A;color:#111;font-weight:800;font-size:15px;text-decoration:none;padding:13px 26px;border-radius:10px">Choose a new password</a>
            </p>
            <p style="font-size:13px;line-height:1.5;color:#666;margin:0 0 6px">
                If the button doesn't work, copy and paste this link into your browser:
            </p>
            <p style="font-size:12px;line-height:1.4;color:#666;word-break:break-all;margin:0 0 18px">{{ $link }}</p>
            <p style="font-size:13px;line-height:1.5;color:#666;margin:0">
                Didn't ask for this? You can safely ignore this email — your password won't change.
            </p>
        </div>
        <p style="text-align:center;color:#999;font-size:11px;margin:14px 0 0">Central Executive Transfers Ltd · Sheffield</p>
    </div>
</body>
</html>
