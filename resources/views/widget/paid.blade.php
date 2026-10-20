<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($unavailable ?? false) ? 'Payment' : 'Booking confirmed' }} · Central Executive Transfers</title>
    <style>
        :root{
            --gold:#FBBA2A;--gold-deep:#e0a310;--ink:#0b0b0b;
            --paper:#f4f2ee;--muted:#6f6b63;--line:#e7e3da;--success:#1f7a44;--success-bg:#eaf6ef;
        }
        *{box-sizing:border-box}html,body{margin:0}
        body{
            font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
            color:var(--ink);line-height:1.55;-webkit-font-smoothing:antialiased;background:transparent;
        }
        html.standalone body{
            background:
                radial-gradient(1200px 520px at 50% -140px, rgba(251,186,42,.16), transparent 60%),
                var(--paper);
            min-height:100vh;display:grid;place-items:center;padding:24px;
        }
        .card{
            background:#fff;border:1px solid var(--line);border-radius:22px;
            box-shadow:0 24px 70px -28px rgba(11,11,11,.38);
            max-width:500px;width:100%;margin:0 auto;overflow:hidden;
        }
        .masthead{background:linear-gradient(160deg,#141414 0%,#000 100%);padding:28px 34px 24px;text-align:center;position:relative}
        .masthead::after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;
            background:linear-gradient(90deg,var(--gold-deep),var(--gold),var(--gold-deep))}
        .logo{width:248px;max-width:80%;height:auto;display:block;margin:0 auto;opacity:.98}
        .tagline{margin:12px 0 0;color:#c9c6bd;font-size:10.5px;letter-spacing:.28em;text-transform:uppercase;font-weight:600}
        .body{padding:34px 34px 30px;text-align:center}
        .tick{
            width:66px;height:66px;border-radius:50%;margin:0 auto 20px;display:grid;place-items:center;font-size:30px;font-weight:700;
            background:var(--success-bg);color:var(--success);box-shadow:0 0 0 7px rgba(31,122,68,.10);
        }
        .tick.wait{background:#fff6e3;color:var(--gold-deep);box-shadow:0 0 0 7px rgba(251,186,42,.12)}
        h1{font-size:27px;margin:0 0 10px;letter-spacing:-.02em;font-weight:800}
        p{color:var(--muted);margin:0 auto 10px;font-size:15.5px;max-width:40ch}
        p strong{color:var(--ink);font-weight:600}
        .meta{margin:22px auto 0;max-width:360px;text-align:left;border:1px solid var(--line);border-radius:14px;padding:4px 16px;background:#fbfaf7}
        .meta .row{display:flex;align-items:center;gap:12px;padding:11px 0;font-size:14px}
        .meta .row+.row{border-top:1px solid var(--line)}
        .meta .ic{width:22px;text-align:center;flex:0 0 22px;font-size:16px}
        .meta .row b{color:var(--ink);font-weight:600}
        .brand{margin:26px -34px -30px;padding:18px 34px;background:#faf9f6;border-top:1px solid var(--line);color:var(--muted);font-size:12.5px;line-height:1.7}
        .brand b{color:var(--ink)}
        @media (max-width:420px){.body{padding:28px 22px 26px}.masthead{padding:24px 22px 20px}.brand{margin:24px -22px -26px;padding:16px 22px}}
    </style>
</head>
<body>
    <div class="card" id="cet-widget">
        <div class="masthead">
            <img class="logo" src="{{ asset('images/cet-logo-wide.png') }}" onerror="this.onerror=null;this.src='{{ asset('images/cet-logo.jpg') }}';this.style.width='60px';this.style.borderRadius='13px'" alt="Central Executive Transfers">
            <p class="tagline">Executive Chauffeur Service</p>
        </div>
        <div class="body">
            @if($already ?? false)
                <div class="tick">✓</div>
                <h1>Already paid</h1>
                <p>This journey is <strong>paid in full</strong> — there's nothing more to pay.</p>
                <p>We'll send your driver's details before pick-up. Safe travels.</p>
            @elseif($unavailable ?? false)
                <div class="tick wait">🕓</div>
                <h1>We'll confirm your fare</h1>
                <p>Online payment isn't available for this journey just yet — our office will <strong>confirm the price</strong> and how to pay.</p>
                <p>Your booking request is safe.</p>
            @else
                <div class="tick">✓</div>
                <h1>Booking confirmed</h1>
                <p>Thank you — your payment was successful and your <strong>chauffeur is booked</strong>.</p>
                <div class="meta">
                    <div class="row"><span class="ic">📧</span><span>A confirmation &amp; <b>receipt</b> are on their way to your inbox.</span></div>
                    <div class="row"><span class="ic">🚘</span><span>We'll send your <b>driver's details</b> before pick-up.</span></div>
                    <div class="row"><span class="ic">📞</span><span>Need us sooner? Call <b>07405 172435</b>.</span></div>
                </div>
            @endif
            <div class="brand">
                <b>Central Executive Transfers Ltd</b><br>
                Operator Licence OP037 · 07405 172435 · centralexecutivetransfers.co.uk
            </div>
        </div>
    </div>
    <script>
        if (window.self === window.top) { document.documentElement.classList.add('standalone'); }
        try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
    </script>
</body>
</html>
