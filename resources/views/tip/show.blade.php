<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Tip your driver · Central Executive Transfers</title>
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

    <main class="container" style="max-width:520px;padding:22px 16px 48px">
        @if(session('tipError'))
            <div class="alert" style="background:rgba(179,32,32,.1);border-left:4px solid #b32020">{{ session('tipError') }}</div>
        @endif

        @if(! $booking)
            <div class="card" style="text-align:center">
                <div style="font-size:34px">🔒</div>
                <h1 style="font-size:19px;margin:8px 0 6px">Link not found</h1>
                <p class="hint" style="margin:0">This tip link isn’t valid or has expired. If you’d still like to leave a tip, please contact the office.</p>
            </div>
        @elseif(! $enabled)
            <div class="card" style="text-align:center">
                <div style="font-size:34px">💛</div>
                <h1 style="font-size:19px;margin:8px 0 6px">Thank you</h1>
                <p class="hint" style="margin:0">Card tips aren’t available just yet. Thank you for thinking of {{ $driverName ?: 'your driver' }} — it’s appreciated.</p>
            </div>
        @else
            <div class="card" style="text-align:center">
                <div style="font-size:38px">💛</div>
                <h1 style="font-size:21px;margin:10px 0 4px">Thank {{ $driverName ?: 'your driver' }}</h1>
                <p class="hint" style="margin:0 0 18px">If you enjoyed your journey with Central Executive Transfers, you can leave {{ $driverName ? $driverName.'' : 'your driver' }} a tip below. 100% goes to the driver.</p>

                <form method="POST" action="{{ route('tip.pay', $token) }}" id="tip-form">
                    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:16px">
                        @foreach($amounts as $amt)
                            <button type="submit" name="amount" value="{{ $amt }}" class="btn btn-primary" style="padding:16px 0;font-size:19px;font-weight:800;min-width:96px;flex:1 1 96px;max-width:140px">£{{ $amt }}</button>
                        @endforeach
                    </div>

                    <div style="border-top:1px solid rgba(128,128,128,.15);padding-top:14px">
                        <label for="custom" class="hint" style="display:block;margin-bottom:6px">Or another amount</label>
                        <div style="display:flex;gap:8px;justify-content:center;align-items:center">
                            <span style="font-size:20px;font-weight:700">£</span>
                            <input id="custom" name="amount" type="number" step="0.50" min="1" max="500" placeholder="25" inputmode="decimal" style="width:120px;font-size:18px;text-align:center">
                            <button type="submit" class="btn btn-dark" style="padding:12px 18px;font-size:15px">Tip →</button>
                        </div>
                    </div>
                </form>

                <p class="hint" style="margin:18px 0 0;font-size:12px">Secure card payment powered by Square. Your card details are never seen or stored by us.</p>
            </div>
        @endif

        <p style="text-align:center;margin-top:22px;font-size:13px;color:#8a8f98">
            <strong>Central Executive Transfers</strong> — “Driven by Excellence”
        </p>
    </main>
</body>
</html>
