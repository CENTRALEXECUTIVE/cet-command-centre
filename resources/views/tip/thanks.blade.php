<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Thank you · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('cet.asset_version') }}">
    @include('partials.pwa')
</head>
<body class="driver-app">
    <div class="link-topbar">
        <span class="brand"><span class="dot"></span> CENTRAL <span class="gold">EXECUTIVE</span></span>
        <span class="link-tag">Tip</span>
    </div>

    <main class="container" style="max-width:520px;padding:40px 16px 48px">
        <div class="card" style="text-align:center">
            <div style="font-size:42px">🙏</div>
            <h1 style="font-size:22px;margin:10px 0 6px">Thank you</h1>
            <p class="hint" style="margin:0">Your tip means a great deal to {{ $driverName ?: 'your driver' }}. We look forward to driving you again soon.</p>
        </div>
        <p style="text-align:center;margin-top:22px;font-size:13px;color:#8a8f98">
            <strong>Central Executive Transfers</strong> — “Driven by Excellence”
        </p>
    </main>
</body>
</html>
