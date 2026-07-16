<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your job · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('cet.asset_version') }}">
    @include('partials.pwa')
</head>
<body class="driver-app">
    <div class="link-topbar">
        <span class="brand"><span class="dot"></span> CENTRAL <span class="gold">EXECUTIVE</span></span>
        <span class="link-tag">Driver job</span>
    </div>

    <main class="container" style="max-width:640px;padding:18px 16px 48px">
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        {{-- In-app browsers (esp. WhatsApp on iPhone) block location and can drop
             the session. Nudge the driver into their real browser where it all
             works. Only shows when we detect an in-app browser. --}}
        <div id="open-external" class="card" style="display:none;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.10)">
            <div style="font-weight:800;font-size:15px">📲 Open in your browser</div>
            <p class="hint" style="margin:6px 0 10px">You’re in WhatsApp’s built-in browser. For location sharing and the buttons to work reliably <strong>on iPhone</strong>, tap the <strong>⋯</strong> (or <strong>aA</strong>) menu and choose <strong>Open in Safari / Chrome</strong>.</p>
            <button type="button" id="copy-job-link" class="btn btn-primary" style="padding:8px 16px;font-size:14px">⧉ Copy this link</button>
            <span id="copy-job-done" class="hint" style="color:#6fdd9c;margin-left:8px"></span>
        </div>

        @verbatim
        <script>
            (function () {
                var ua = navigator.userAgent || '';
                var inApp = /WhatsApp|FBAN|FBAV|Instagram|Line\/|Messenger|Snapchat|GSA/i.test(ua);
                if (inApp) {
                    var box = document.getElementById('open-external');
                    if (box) box.style.display = 'block';
                }
                var btn = document.getElementById('copy-job-link');
                if (btn) btn.addEventListener('click', function () {
                    var done = document.getElementById('copy-job-done');
                    var finish = function () { if (done) done.textContent = '✓ Copied — paste it in Safari'; };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(window.location.href).then(finish).catch(finish);
                    } else { finish(); }
                });
            })();
        </script>
        @endverbatim

        @if($booking->status->isTerminal())
            {{-- Job done → the link closes. Kept tidy (no details), not a raw 404. --}}
            <div class="card" style="text-align:center;padding:36px 20px">
                <div style="font-size:40px">✅</div>
                <h2 style="margin:10px 0 4px">This job is {{ $booking->status->label() }}</h2>
                <p class="hint" style="margin:0">Thanks — this link is now closed. Central Executive Transfers will send a fresh link for your next job.</p>
            </div>
        @else
            @include('driver._job', [
                'linkMode' => true,
                'statusUrl' => route('driver.link.status', $token),
                'locationStoreUrl' => route('driver.link.location', $token),
            ])

            <p class="hint" style="text-align:center;margin-top:20px">Private link for this job · Central Executive Transfers</p>
        @endif
    </main>
</body>
</html>
