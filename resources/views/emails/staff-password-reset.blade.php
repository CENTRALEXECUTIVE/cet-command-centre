<!DOCTYPE html>
<html><body style="margin:0;background:#f4f3ef;font-family:'Segoe UI',Helvetica,Arial,sans-serif;color:#141414">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border-radius:14px;overflow:hidden">
<tr><td style="background:#0d0d0d;padding:18px 24px;border-bottom:3px solid #FBBA2A;color:#fff;font-weight:800;letter-spacing:.06em">CET COMMAND CENTRE</td></tr>
<tr><td style="padding:24px">
<p style="margin:0 0 12px;font-size:16px">Someone asked to reset the Command Centre password for <strong>{{ $user->name }}</strong> ({{ $user->email }}).</p>
<p style="margin:0 0 20px;font-size:14px;color:#555">The link works for one hour. If this wasn't you, ignore this email and nothing changes.</p>
<a href="{{ $link }}" style="display:inline-block;background:#FBBA2A;color:#111;text-decoration:none;padding:12px 20px;border-radius:9px;font-weight:800">Set a new password</a>
<p style="margin:20px 0 0;font-size:12px;color:#888;word-break:break-all">{{ $link }}</p>
</td></tr></table></td></tr></table>
</body></html>
