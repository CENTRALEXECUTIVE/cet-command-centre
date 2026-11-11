<!doctype html>
{{-- Directors' chosen look (11 Nov 2026, the Sheffield Forgemasters email): Arial 14px. --}}
<html><body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#111;background:#fff">
<div style="max-width:620px">{!! nl2br(e($bodyText)) !!}</div>
<div style="margin-top:18px;font-size:14px;line-height:1.5;color:#111">
    <strong>{{ $sig['name'] ?? 'Matthew Green' }}</strong><br>
    <strong>{{ $sig['company'] ?? 'Central Executive Transfers' }}</strong><br>
    <a href="tel:{{ preg_replace('/\s+/', '', $sig['phone'] ?? '01143998242') }}" style="color:#1a5fd0">{{ $sig['phone'] ?? '0114 399 8242' }}</a><br>
    <a href="{{ $sig['website_url'] ?? 'https://www.centralexecutivetransfers.co.uk' }}" style="color:#1a5fd0">{{ $sig['website'] ?? 'www.centralexecutivetransfers.co.uk' }}</a><br>
    <img src="{{ $sig['logo_url'] ?? url('/images/cet-email-signature.png') }}" alt="CENTRAL" width="180" style="display:block;margin-top:8px;border:0">
</div>
</body></html>
