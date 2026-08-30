<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment · Central Executive Transfers</title>
    <style>
        :root { --gold:#FBBA2A; --muted:#666; }
        html,body { margin:0; }
        body { font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#111; background:transparent; }
        .cet-widget { max-width:520px; margin:0 auto; background:#fff; border:1px solid #e6e6e6; border-radius:14px; padding:24px; text-align:center; }
        .tick { font-size:46px; }
        .brand { font-weight:800; letter-spacing:.5px; font-size:13px; color:#111; margin-bottom:10px; }
        .brand span { color:var(--gold); }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="brand">CENTRAL <span>EXECUTIVE</span> TRANSFERS</div>
        @if($unavailable)
            <div class="tick">🕓</div>
            <h2 style="margin:8px 0 4px">We'll confirm your fare</h2>
            <p style="color:var(--muted);margin:0">Online payment isn't available for this journey just yet — our office will confirm the price and how to pay. Your booking request is safe.</p>
        @else
            <div class="tick">✅</div>
            <h2 style="margin:8px 0 4px">Thank you</h2>
            <p style="color:var(--muted);margin:0">If your payment went through, it's confirmed automatically — you'll get your booking confirmation from our office. Safe travels with Central Executive Transfers.</p>
        @endif
    </div>
    <script>
        try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
    </script>
</body>
</html>
