<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment · Central Executive Transfers</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%230b0b0c'/><text x='50' y='72' font-size='64' text-anchor='middle' fill='%23FBBA2A' font-family='Georgia,serif' font-weight='bold'>C</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:#FBBA2A; --gold-deep:#E9A413; --ink:#0b0b0c; --paper:#fff;
            --line:#e9e7e0; --muted:#6a6a70; --ok:#1f8b4c;
            --shadow:0 24px 60px -24px rgba(0,0,0,.45), 0 8px 24px -12px rgba(0,0,0,.25); --radius:16px;
        }
        * { box-sizing:border-box; }
        html, body { margin:0; }
        body { font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:transparent; line-height:1.45; -webkit-font-smoothing:antialiased; }
        html.standalone body {
            background:radial-gradient(1200px 500px at 50% -10%, rgba(251,186,42,.18), transparent 60%),
                linear-gradient(180deg, #0b0b0c 0%, #121216 55%, #0b0b0c 100%);
            min-height:100vh; padding:32px 16px 44px;
        }
        .cet-widget { max-width:520px; margin:0 auto; background:var(--paper); border:1px solid var(--line);
            border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
        .cet-hero { background:radial-gradient(600px 200px at 12% -40%, rgba(251,186,42,.30), transparent 60%),
            linear-gradient(135deg, #17171a 0%, #0b0b0c 100%); color:#fff; padding:16px 22px; position:relative; }
        .cet-hero::after { content:""; position:absolute; left:0; right:0; bottom:0; height:3px; background:linear-gradient(90deg, var(--gold), var(--gold-deep)); }
        .cet-brand { display:flex; align-items:center; gap:10px; }
        .cet-mark { width:32px; height:32px; border-radius:9px; background:var(--gold); color:#0b0b0c; font-family:Georgia,serif; font-weight:800; font-size:21px; display:grid; place-items:center; }
        .cet-brand .name { font-weight:800; letter-spacing:1.4px; font-size:14px; } .cet-brand .name span { color:var(--gold); }
        .cet-body { padding:30px 24px 32px; text-align:center; }
        .tick { width:72px; height:72px; border-radius:50%; margin:0 auto 14px; display:grid; place-items:center; font-size:38px;
            background:radial-gradient(circle at 30% 25%, rgba(251,186,42,.25), transparent 70%); border:2px solid rgba(251,186,42,.5); }
        .cet-body h2 { margin:4px 0 8px; font-size:22px; font-weight:800; }
        .cet-body p { color:var(--muted); margin:0; font-size:14.5px; }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="cet-hero">
            <div class="cet-brand"><span class="cet-mark">C</span><span class="name">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>
        </div>
        <div class="cet-body">
            @if($unavailable)
                <div class="tick">🕓</div>
                <h2>We'll confirm your fare</h2>
                <p>Online payment isn't available for this journey just yet — our office will confirm the price and how to pay. Your booking request is safe.</p>
            @else
                <div class="tick">✅</div>
                <h2>Thank you</h2>
                <p>If your payment went through, it's confirmed automatically — you'll get your booking confirmation from our office. Safe travels with Central Executive Transfers.</p>
            @endif
        </div>
    </div>
    <script>
        if (window.self === window.top) { document.documentElement.classList.add('standalone'); }
        try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
    </script>
</body>
</html>
